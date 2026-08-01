// This file is part of Moodle and is licensed under the
// GNU General Public License, version 3 or later.
//
// You may redistribute and modify it under the terms of the GPL.
// See the plugin root LICENSE file for complete terms.

/**
 * React learner subscription summary page for Modern Commerce.
 *
 * @module     local_moderncommerce/learner_subscription
 * @copyright  2025 Adebare Showemimo | adebareshowemimo@gmail.com | support@agunfoninteractivity.com | www.agunfoninteractivity.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {useEffect, useState} from "react";
import type {ReactNode} from "react";
import {badgeClass, callMoodleService, Labels} from "./learner_common";
import {mcClasses, useModernCommerceClassSync} from "./design_system";
import ModernLearnerLayout, {type LearnerLayoutContext} from "./learner_layout";
import SubscriptionAccessSections, {type SubscriptionAccess} from "./subscription_access_sections";
import {LearnerStatStrip, LearnerStatTile} from "./learner_stat_tiles";

type Subscription = {
    id: number;
    planid: number;
    status: string;
    statuslabel: string;
    statusclass: string;
    isactive: boolean;
    startdate: string;
    enddate: string;
    daysremaining: number;
    hasdaysremaining: boolean;
    autorenew: boolean;
    accountcredit: string;
    hasaccountcredit: boolean;
    cancelatperiodend: boolean;
};

type Plan = {
    id: number;
    name: string;
    description: string;
    billingcycle: string;
    price: string;
};

type Feature = {
    text: string;
    icon: string;
};

type Counts = {
    courses: number;
    bundles: number;
    categories: number;
};

type Actions = {
    canchangeplan: boolean;
    haspendingchange: boolean;
    cancancel: boolean;
    cancelscheduled: boolean;
    canrenew: boolean;
    isexpired: boolean;
    isexpiring: boolean;
    istrial: boolean;
};

type SubscriptionResponse = {
    success: boolean;
    available: boolean;
    hassubscription: boolean;
    message: string;
    subscription: Subscription;
    plan: Plan;
    features: Feature[];
    counts: Counts;
    actions: Actions;
    urls: {
        catalog: string;
        orders: string;
        courses: string;
        access: string;
        plans: string;
        billinghistory: string;
        changeplan: string;
        cancel: string;
        renew: string;
        converttrial: string;
    };
};

type AccessResponse = {
    success: boolean;
    hassubscription: boolean;
    courses: SubscriptionAccess["courses"];
    categories: SubscriptionAccess["categories"];
    bundles: SubscriptionAccess["bundles"];
};

type LearnerSubscriptionProps = {
    methodName: string;
    accessMethodName?: string;
    subscriptionId: number;
    planId: number;
    labels: Labels;
    layout?: LearnerLayoutContext;
};

function LoadingState({labels}: {labels: Labels}) {
    return (
        <div className={mcClasses("mc-empty mc-empty--centered")}>
            <span className={mcClasses("mc-empty__icon")}><i className="bi bi-credit-card" aria-hidden="true" /></span>
            <p className={mcClasses("mc-empty__title")}>{labels.loading}</p>
        </div>
    );
}

function EmptyState({
    title,
    message,
    labels,
    catalogUrl,
    plansUrl,
}: {
    title: string;
    message: string;
    labels: Labels;
    catalogUrl: string;
    plansUrl: string;
}) {
    return (
        <div className={mcClasses("mc-empty mc-empty--centered")}>
            <span className={mcClasses("mc-empty__icon")}><i className="bi bi-credit-card" aria-hidden="true" /></span>
            <p className={mcClasses("mc-empty__title")}>{title}</p>
            {message && <p className={mcClasses("mc-empty__desc")}>{message}</p>}
            <div className="d-flex gap-2 justify-content-center flex-wrap">
                <a className={mcClasses("mc-button btn-mc-primary")} href={catalogUrl}>
                    <i className="bi bi-grid me-1" aria-hidden="true" />
                    {labels.browsecatalog}
                </a>
                <a className={mcClasses("mc-button btn-mc-secondary")} href={plansUrl}>
                    <i className="bi bi-credit-card me-1" aria-hidden="true" />
                    {labels.browseplans}
                </a>
            </div>
        </div>
    );
}

function DetailRow({
    label,
    value,
}: {
    label: string;
    value: string;
}) {
    if (!value) {
        return null;
    }

    return (
        <div className={mcClasses("mc-detail-list__row")}>
            <span className={mcClasses("mc-detail-list__label")}>{label}</span>
            <span className={mcClasses("mc-detail-list__value")}>{value}</span>
        </div>
    );
}

const interpolate = (template: string, values: Record<string, string>): string => {
    return Object.entries(values).reduce((text, [key, value]) => {
        return text.split(`{${key}}`).join(value);
    }, template);
};

function SubscriptionNotice({
    subscription,
    actions,
    labels,
}: {
    subscription: Subscription;
    actions: Actions;
    labels: Labels;
}) {
    const date = subscription.enddate || labels.lifetime;
    const days = String(subscription.daysremaining);
    let variant = "primary";
    let icon = "bi-shield-check";
    let message = interpolate(labels.subscriptionnotice_active, {date});

    if (subscription.cancelatperiodend) {
        variant = "warning";
        icon = "bi-calendar-x";
        message = interpolate(labels.subscriptionnotice_cancelscheduled, {date});
    } else if (actions.isexpired) {
        variant = "danger";
        icon = "bi-exclamation-octagon";
        message = labels.subscriptionnotice_expired;
    } else if (actions.istrial) {
        variant = "info";
        icon = "bi-hourglass-split";
        message = interpolate(labels.subscriptionnotice_trial, {date});
    } else if (actions.isexpiring || subscription.hasdaysremaining) {
        variant = "warning";
        icon = "bi-clock-history";
        message = interpolate(labels.subscriptionnotice_expiring, {days, date});
    } else if (subscription.enddate === labels.lifetime) {
        variant = "success";
        icon = "bi-infinity";
        message = labels.subscriptionnotice_lifetime;
    } else if (subscription.autorenew) {
        variant = "success";
        icon = "bi-arrow-repeat";
        message = interpolate(labels.subscriptionnotice_autorenew, {date});
    }

    return (
        <div className={mcClasses(`mc-subscription-status-panel__notice mc-subscription-status-panel__notice--${variant}`)}>
            <i className={`bi ${icon}`} aria-hidden="true" />
            <span>{message}</span>
        </div>
    );
}

function SubscriptionStatusPanel({
    subscription,
    plan,
    actions,
    urls,
    labels,
    hasAccessDetails,
}: {
    subscription: Subscription;
    plan: Plan;
    actions: Actions;
    urls: SubscriptionResponse["urls"];
    labels: Labels;
    hasAccessDetails: boolean;
}) {
    const urgentRenewal = actions.canrenew && (actions.isexpired || actions.isexpiring || subscription.hasdaysremaining);
    const primaryHref = urgentRenewal ? urls.renew : hasAccessDetails ? "#mc-subscription-access-details" : urls.courses;
    const primaryLabel = urgentRenewal ? labels.renewsubscription : hasAccessDetails ? labels.viewsubscriptionaccess : labels.mycourses;
    const primaryIcon = urgentRenewal ? "bi-arrow-clockwise" : hasAccessDetails ? "bi-list-check" : "bi-play-circle";

    return (
        <div className={mcClasses("mc-card h-100 mc-subscription-status-panel")}>
            <div className={mcClasses("mc-card-header")}>
                <div>
                    <h2 className={mcClasses("mc-card-title")}>{labels.subscriptionstatussummary}</h2>
                    <span className={mcClasses("mc-cell-muted small")}>{labels.subscriptionperiod}</span>
                </div>
                <span className={mcClasses(`mc-badge mc-badge--${badgeClass(subscription.statusclass)}`)}>
                    {subscription.statuslabel}
                </span>
            </div>
            <div className={mcClasses("mc-card-body")}>
                <div className={mcClasses("mc-subscription-status-panel__summary")}>
                    <div>
                        <span className={mcClasses("mc-subscription-status-panel__label")}>{labels.plan}</span>
                        <strong className={mcClasses("mc-subscription-status-panel__name")}>{plan.name}</strong>
                        {plan.description && <p>{plan.description}</p>}
                    </div>
                    <div className={mcClasses("mc-subscription-status-panel__price")}>
                        <strong>{plan.price}</strong>
                        {plan.billingcycle && <span>{plan.billingcycle}</span>}
                    </div>
                </div>

                <SubscriptionNotice subscription={subscription} actions={actions} labels={labels} />

                <div className={mcClasses("mc-detail-list")}>
                    <DetailRow label={labels.status} value={subscription.statuslabel} />
                    <DetailRow label={labels.startdate} value={subscription.startdate} />
                    <DetailRow label={labels.enddate} value={subscription.enddate} />
                    {subscription.hasaccountcredit && <DetailRow label={labels.accountcredit} value={subscription.accountcredit} />}
                </div>

                <div className={mcClasses("mc-subscription-status-panel__flags")}>
                    {subscription.autorenew && (
                        <span className={mcClasses("mc-badge mc-badge--success")}>
                            <i className="bi bi-arrow-repeat" aria-hidden="true" />
                            {labels.autorenew}
                        </span>
                    )}
                    {subscription.cancelatperiodend && (
                        <span className={mcClasses("mc-badge mc-badge--warning")}>
                            <i className="bi bi-calendar-x" aria-hidden="true" />
                            {labels.cancelatperiodend}
                        </span>
                    )}
                </div>

                <div className={mcClasses("mc-subscription-status-panel__actions")}>
                    <a className={mcClasses("mc-button btn-mc-primary")} href={primaryHref}>
                        <i className={`bi ${primaryIcon}`} aria-hidden="true" />
                        {primaryLabel}
                    </a>
                    <a className={mcClasses("mc-button btn-mc-secondary")} href={urls.billinghistory}>
                        <i className="bi bi-clock-history" aria-hidden="true" />
                        {labels.billinghistory}
                    </a>
                </div>
            </div>
        </div>
    );
}

function QuickActions({
    actions,
    urls,
    labels,
}: {
    actions: Actions;
    urls: SubscriptionResponse["urls"];
    labels: Labels;
}) {
    return (
        <div className={mcClasses("mc-card h-100 mc-subscription-actions-panel")}>
            <div className={mcClasses("mc-card-header")}>
                <h2 className={mcClasses("mc-card-title")}>{labels.actions}</h2>
            </div>
            <div className={mcClasses("mc-card-body d-grid gap-2")}>
                <a className={mcClasses("mc-button btn-mc-secondary")} href={urls.courses}>
                    <i className="bi bi-play-circle" aria-hidden="true" />
                    {labels.mycourses}
                </a>
                <a className={mcClasses("mc-button btn-mc-secondary")} href={urls.orders}>
                    <i className="bi bi-receipt" aria-hidden="true" />
                    {labels.orders}
                </a>
                {actions.canchangeplan && (
                    <a className={mcClasses("mc-button btn-mc-secondary")} href={urls.changeplan}>
                        <i className="bi bi-arrow-left-right" aria-hidden="true" />
                        {labels.changeplan}
                    </a>
                )}
                {actions.canrenew && (
                    <a className={mcClasses("mc-button btn-mc-secondary")} href={urls.renew}>
                        <i className="bi bi-arrow-clockwise" aria-hidden="true" />
                        {labels.renewsubscription}
                    </a>
                )}
                {actions.cancancel && (
                    <a className={mcClasses("mc-button btn-mc-secondary")} href={urls.cancel}>
                        <i className="bi bi-slash-circle" aria-hidden="true" />
                        {labels.cancel}
                    </a>
                )}
            </div>
        </div>
    );
}

export default function LearnerSubscription({
    methodName,
    accessMethodName,
    subscriptionId,
    planId,
    labels,
    layout,
}: LearnerSubscriptionProps) {
    useModernCommerceClassSync();
    const [data, setData] = useState<SubscriptionResponse | null>(null);
    const [access, setAccess] = useState<SubscriptionAccess | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");

    useEffect(() => {
        let cancelled = false;

        setLoading(true);
        setError("");

        callMoodleService<SubscriptionResponse>(methodName, {id: subscriptionId, planid: planId})
            .then((result) => {
                if (!cancelled) {
                    setData(result);
                }
                return result;
            })
            .catch((caught: Error) => {
                if (!cancelled) {
                    setError(caught.message);
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [methodName, planId, subscriptionId]);

    useEffect(() => {
        if (!accessMethodName) {
            return;
        }
        let cancelled = false;
        callMoodleService<AccessResponse>(accessMethodName, {id: subscriptionId, planid: planId})
            .then((result) => {
                if (!cancelled && result.success && result.hassubscription) {
                    setAccess({courses: result.courses, categories: result.categories, bundles: result.bundles});
                }
                return result;
            })
            .catch(() => {
                // Access is supplementary; never block the summary on it.
            });

        return () => {
            cancelled = true;
        };
    }, [accessMethodName, planId, subscriptionId]);

    const renderLayout = (children: ReactNode, title = labels.mysubscriptionplan, subtitle?: string, actions?: ReactNode) => (
        <ModernLearnerLayout
            activeNav="subscriptions"
            title={title}
            subtitle={subtitle}
            labels={labels}
            layout={layout}
            actions={actions}
        >
            {children}
        </ModernLearnerLayout>
    );

    if (loading) {
        return renderLayout(<LoadingState labels={labels} />);
    }

    if (error || !data || !data.success) {
        return renderLayout(
            <div className={mcClasses("mc-alert mc-alert--warning")} role="alert">
                <i className="bi bi-exclamation-triangle mc-alert__icon" aria-hidden="true" />
                <div className={mcClasses("mc-alert__body")}>{error || data?.message}</div>
            </div>
        );
    }

    if (!data.available) {
        return renderLayout(
            <EmptyState
                title={labels.subscriptionsunavailable}
                message={data.message}
                labels={labels}
                catalogUrl={data.urls.catalog}
                plansUrl={data.urls.plans}
            />
        );
    }

    if (!data.hassubscription) {
        return renderLayout(
            <EmptyState
                title={labels.noactivesubscription}
                message={data.message}
                labels={labels}
                catalogUrl={data.urls.catalog}
                plansUrl={data.urls.plans}
            />
        );
    }

    const {subscription, plan, counts, actions, urls} = data;
    const headerActions = (
        <div className="d-flex align-items-center gap-2 flex-wrap">
            <span className={mcClasses(`mc-badge mc-badge--${badgeClass(subscription.statusclass)}`)}>
                {subscription.statuslabel}
            </span>
        </div>
    );

    return renderLayout(
        <div className={mcClasses("mc-learner-subscription")}>
            <LearnerStatStrip>
                <LearnerStatTile label={labels.totalcourses} value={counts.courses} icon="bi-play-circle" variant="primary" />
                <LearnerStatTile label={labels.bundles} value={counts.bundles} icon="bi-layers" variant="info" />
                <LearnerStatTile label={labels.category} value={counts.categories} icon="bi-folder2" variant="warning" />
            </LearnerStatStrip>

            <div className="row g-3">
                <div className="col-lg-8">
                    <SubscriptionStatusPanel
                        subscription={subscription}
                        plan={plan}
                        actions={actions}
                        urls={urls}
                        labels={labels}
                        hasAccessDetails={access !== null}
                    />
                </div>

                <div className="col-lg-4">
                    <QuickActions actions={actions} urls={urls} labels={labels} />
                </div>

                {data.features.length > 0 && (
                    <div className="col-12">
                        <div className={mcClasses("mc-card")}>
                            <div className={mcClasses("mc-card-header")}>
                                <h2 className={mcClasses("mc-card-title")}>{labels.features}</h2>
                            </div>
                            <div className={mcClasses("mc-card-body")}>
                                <div className="row g-2">
                                    {data.features.map((feature, index) => (
                                        <div className="col-md-6" key={`${feature.text}-${index}`}>
                                            <div className="d-flex align-items-start gap-2">
                                                <i className={`bi bi-${feature.icon}`} aria-hidden="true" />
                                                <span>{feature.text}</span>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>
                    </div>
                )}

                {access && (
                    <div className="col-12">
                        <div className={mcClasses("mc-card")} id="mc-subscription-access-details">
                            <div className={mcClasses("mc-card-header")}>
                                <h2 className={mcClasses("mc-card-title")}>{labels.accessdetails}</h2>
                            </div>
                            <div className={mcClasses("mc-card-body")}>
                                <SubscriptionAccessSections access={access} labels={labels} />
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </div>,
        plan.name,
        `${plan.price}${plan.billingcycle ? ` - ${plan.billingcycle}` : ""}`,
        headerActions
    );
}
