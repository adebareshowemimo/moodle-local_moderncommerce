// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Single React entry point for Modern Commerce learner pages.
 *
 * @module     local_moderncommerce/learner_app
 * @copyright  2025 Adebare Showemimo | adebareshowemimo@gmail.com | support@agunfoninteractivity.com | www.agunfoninteractivity.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {useEffect, useState} from "react";
import type {ReactNode} from "react";
import type {Labels} from "./learner_common";
import Cart from "./cart";
import Checkout from "./checkout";
import Redeem from "./redeem";
import LearnerCalendar from "./learner_calendar";
import LearnerCertificates from "./learner_certificates";
import LearnerBundles from "./learner_bundles";
import LearnerCourses from "./learner_courses";
import LearnerDashboard from "./learner_dashboard";
import LearnerGrades from "./learner_grades";
import LearnerLibrary from "./learner_library";
import ModernLearnerLayout, {type LearnerLayoutContext} from "./learner_layout";
import LearnerOrder from "./learner_order";
import LearnerOrders from "./learner_orders";
import LearnerProductAccess from "./learner_product_access";
import LearnerProfile from "./learner_profile";
import LearnerSubscription from "./learner_subscription";
import LearnerWishlist from "./learner_wishlist";
import {mcClasses} from "./design_system";

type Services = {
    catalog: string;
    dashboard: string;
    courses: string;
    orders: string;
    order: string;
    wishlist: string;
    wishlistUpdate: string;
    certificates: string;
    subscription: string;
    subscriptionAccess: string;
    productAccess: string;
    grades: string;
    cart: string;
    cartUpdate: string;
    checkoutStart: string;
    checkoutPlaceOrder: string;
    redeemValidate: string;
    redeem: string;
    profileGet: string;
    profileSave: string;
    profileSavePicture: string;
};

type CalendarContext = {
    monthHtml: string;
    monthFooterHtml: string;
    upcomingHtml: string;
    calendarUrl: string;
    upcomingUrl: string;
};

type LearnerAppProps = {
    services: Services;
    labels: Labels;
    layout?: LearnerLayoutContext;
    calendar: CalendarContext;
    urls: {
        catalog: string;
        courses: string;
    };
};

type RouteState = {
    path: string;
    segments: string[];
    query: URLSearchParams;
    key: string;
};

const parseRoute = (): RouteState => {
    const hash = window.location.hash.replace(/^#/, "") || "/dashboard";
    const [rawPath, rawQuery = ""] = hash.split("?");
    const path = rawPath.replace(/^\/+/, "") || "dashboard";
    const query = new URLSearchParams(window.location.search);
    const hashQuery = new URLSearchParams(rawQuery);

    hashQuery.forEach((value, name) => {
        query.set(name, value);
    });

    return {
        path,
        segments: path.split("/").filter(Boolean),
        query,
        key: `${path}?${query.toString()}`,
    };
};

const intParam = (query: URLSearchParams, key: string, fallback = 0): number => {
    const value = Number(query.get(key) ?? fallback);
    return Number.isFinite(value) ? Math.max(0, Math.trunc(value)) : fallback;
};

const intSegment = (value: string | undefined, fallback = 0): number => {
    const parsed = Number(value ?? fallback);
    return Number.isFinite(parsed) ? Math.max(0, Math.trunc(parsed)) : fallback;
};

const floatParam = (query: URLSearchParams, key: string, fallback = 0): number => {
    const value = Number(query.get(key) ?? fallback);
    return Number.isFinite(value) ? Math.max(0, value) : fallback;
};

const stringParam = (query: URLSearchParams, key: string, fallback = ""): string => (
    query.get(key) ?? fallback
);

const redeemLabels = (labels: Labels, activeNav: "redeem" | "bundlekeys"): Labels => {
    if (activeNav === "bundlekeys") {
        return {
            ...labels,
            title: labels.redeembundlekey || labels.bundleenrollmentkeys || labels.redeemkey,
            intro: labels.redeembundlekeyheading || labels.bundleenrollmentkeysdesc || labels.redeemintro,
            help: labels.redeemhelp,
        };
    }

    return {
        ...labels,
        title: labels.redeemkey,
        intro: labels.redeemintro,
        help: labels.redeemhelp,
    };
};

const calendarDefaults = (calendar: CalendarContext): CalendarContext => ({
    monthHtml: calendar?.monthHtml ?? "",
    monthFooterHtml: calendar?.monthFooterHtml ?? "",
    upcomingHtml: calendar?.upcomingHtml ?? "",
    calendarUrl: calendar?.calendarUrl ?? "#",
    upcomingUrl: calendar?.upcomingUrl ?? "#",
});

function UnknownRoute({
    labels,
    layout,
}: {
    labels: Labels;
    layout?: LearnerLayoutContext;
}) {
    return (
        <ModernLearnerLayout
            activeNav="dashboard"
            title={labels.dashboard}
            subtitle={labels.learnerdashboardsubtitle}
            labels={labels}
            layout={layout}
        >
            <div className={mcClasses("mc-empty mc-empty--centered")}>
                <span className={mcClasses("mc-empty__icon")}>
                    <i className="bi bi-compass" aria-hidden="true" />
                </span>
                <p className={mcClasses("mc-empty__title")}>{labels.dashboardempty}</p>
                <a className={mcClasses("mc-button btn-mc-primary")} href="#/dashboard">
                    {labels.dashboard}
                </a>
            </div>
        </ModernLearnerLayout>
    );
}

function SubscriptionUnavailable({
    labels,
    layout,
}: {
    labels: Labels;
    layout?: LearnerLayoutContext;
}) {
    return (
        <ModernLearnerLayout
            activeNav="subscriptions"
            title={labels.subscriptionsunavailable}
            subtitle={labels.subscriptionsunavailable_desc}
            labels={labels}
            layout={layout}
        >
            <div className={mcClasses("mc-empty mc-empty--centered")}>
                <span className={mcClasses("mc-empty__icon")}>
                    <i className="bi bi-credit-card" aria-hidden="true" />
                </span>
                <p className={mcClasses("mc-empty__title")}>
                    {labels.subscriptionsunavailable}
                </p>
                {labels.subscriptionsunavailable_desc && (
                    <p className={mcClasses("mc-empty__text")}>{labels.subscriptionsunavailable_desc}</p>
                )}
                <a className={mcClasses("mc-button btn-mc-primary")} href="#/library">
                    {labels.browsecatalog}
                </a>
            </div>
        </ModernLearnerLayout>
    );
}

export default function LearnerApp({
    services,
    labels,
    layout,
    calendar,
    urls,
}: LearnerAppProps) {
    const [route, setRoute] = useState<RouteState>(parseRoute);
    const [first] = route.segments;
    const subscriptionsEnabled = layout?.features?.subscriptions !== false;

    useEffect(() => {
        const handleRouteChange = () => setRoute(parseRoute());

        window.addEventListener("hashchange", handleRouteChange);
        window.addEventListener("popstate", handleRouteChange);

        return () => {
            window.removeEventListener("hashchange", handleRouteChange);
            window.removeEventListener("popstate", handleRouteChange);
        };
    }, []);

    const routeComponents: Record<string, () => ReactNode> = {
        dashboard: () => (
            <LearnerDashboard
                key={route.key}
                methodName={services.dashboard}
                labels={labels}
                layout={layout}
            />
        ),
        library: () => (
            <LearnerLibrary
                key={route.key}
                methodName={services.catalog}
                cartMethodName={services.cartUpdate}
                wishlistUpdateMethodName={services.wishlistUpdate}
                initialFilters={{
                    search: stringParam(route.query, "search"),
                    coursetype: stringParam(route.query, "coursetype"),
                    categoryid: intParam(route.query, "categoryid"),
                    level: stringParam(route.query, "level"),
                    minprice: floatParam(route.query, "minprice"),
                    maxprice: floatParam(route.query, "maxprice"),
                    sort: stringParam(route.query, "sort", "popular"),
                    page: intParam(route.query, "page"),
                    perpage: intParam(route.query, "perpage", 12),
                }}
                labels={labels}
                layout={layout}
            />
        ),
        catalog: () => routeComponents.library(),
        courses: () => (
            <LearnerCourses
                key={route.key}
                methodName={services.courses}
                initialFilters={{
                    search: stringParam(route.query, "search"),
                    categoryid: intParam(route.query, "category"),
                    sort: stringParam(route.query, "sort", "recent"),
                    page: intParam(route.query, "page"),
                    perpage: intParam(route.query, "perpage", 10),
                }}
                labels={labels}
                layout={layout}
            />
        ),
        bundles: () => (
            <LearnerBundles
                key={route.key}
                methodName={services.dashboard}
                labels={labels}
                layout={layout}
            />
        ),
        "my-bundles": () => routeComponents.bundles(),
        orders: () => route.segments[1] ? (
            <LearnerOrder
                key={route.key}
                methodName={services.order}
                orderId={intSegment(route.segments[1])}
                labels={labels}
                layout={layout}
            />
        ) : (
            <LearnerOrders
                key={route.key}
                methodName={services.orders}
                initialFilters={{
                    status: stringParam(route.query, "status"),
                    page: intParam(route.query, "page"),
                    perpage: intParam(route.query, "perpage", 10),
                }}
                labels={labels}
                layout={layout}
            />
        ),
        wishlist: () => (
            <LearnerWishlist
                key={route.key}
                methodName={services.wishlist}
                updateMethodName={services.wishlistUpdate}
                labels={labels}
                layout={layout}
            />
        ),
        order: () => (
            <LearnerOrder
                key={route.key}
                methodName={services.order}
                orderId={route.segments[1] ? intSegment(route.segments[1]) : intParam(route.query, "id")}
                labels={labels}
                layout={layout}
            />
        ),
        certificates: () => (
            <LearnerCertificates
                key={route.key}
                methodName={services.certificates}
                labels={labels}
                layout={layout}
            />
        ),
        subscriptions: () => (
            subscriptionsEnabled ? (
                <LearnerSubscription
                    key={route.key}
                    methodName={services.subscription}
                    accessMethodName={services.subscriptionAccess}
                    subscriptionId={intParam(route.query, "id")}
                    planId={intParam(route.query, "planid")}
                    labels={labels}
                    layout={layout}
                />
            ) : (
                <SubscriptionUnavailable labels={labels} layout={layout} />
            )
        ),
        subscription: () => routeComponents.subscriptions(),
        access: () => {
            const accessType = route.segments[1] || "";
            const productId = intSegment(route.segments[2]);
            const productActiveNav = ["bundle", "program"].includes(accessType) ? "bundles" : "access";

            if (["bundle", "program", "product"].includes(accessType) && productId > 0) {
                return (
                    <LearnerProductAccess
                        key={route.key}
                        methodName={services.productAccess}
                        productId={productId}
                        labels={labels}
                        layout={layout}
                        activeNav={productActiveNav}
                    />
                );
            }

            // Bare #/access is folded into the subscription page (plan + included courses).
            return routeComponents.subscriptions();
        },
        "subscription-access": () => routeComponents.access(),
        bundle: () => (
            <LearnerProductAccess
                key={route.key}
                methodName={services.productAccess}
                productId={intSegment(route.segments[1])}
                labels={labels}
                layout={layout}
                activeNav="bundles"
            />
        ),
        program: () => routeComponents.bundle(),
        cart: () => (
            <Cart
                key={route.key}
                methodName={services.cart}
                updateMethodName={services.cartUpdate}
                labels={labels}
                layout={layout}
            />
        ),
        checkout: () => (
            <Checkout
                key={route.key}
                startMethodName={services.checkoutStart}
                placeOrderMethodName={services.checkoutPlaceOrder}
                cartUpdateMethodName={services.cartUpdate}
                orderId={intParam(route.query, "orderid")}
                courseId={intParam(route.query, "courseid")}
                bundleId={intParam(route.query, "bundleid")}
                labels={labels}
                layout={layout}
            />
        ),
        redeem: () => (
            <Redeem
                key={route.key}
                validateMethodName={services.redeemValidate}
                redeemMethodName={services.redeem}
                orderId={intParam(route.query, "orderid")}
                catalogUrl={urls.catalog}
                coursesUrl={urls.courses}
                labels={redeemLabels(labels, "redeem")}
                layout={layout}
                activeNav="redeem"
            />
        ),
        bundlekeys: () => (
            <Redeem
                key={route.key}
                validateMethodName={services.redeemValidate}
                redeemMethodName={services.redeem}
                orderId={intParam(route.query, "orderid")}
                catalogUrl={urls.catalog}
                coursesUrl={urls.courses}
                labels={redeemLabels(labels, "bundlekeys")}
                layout={layout}
                activeNav="bundlekeys"
            />
        ),
        "redeem-bundle": () => routeComponents.bundlekeys(),
        calendar: () => (
            <LearnerCalendar key={route.key} labels={labels} layout={layout} {...calendarDefaults(calendar)} />
        ),
        grades: () => (
            <LearnerGrades
                key={route.key}
                methodName={services.grades}
                labels={labels}
                layout={layout}
            />
        ),
        profile: () => (
            <LearnerProfile
                key={route.key}
                getMethodName={services.profileGet}
                saveMethodName={services.profileSave}
                savePictureMethodName={services.profileSavePicture}
                labels={labels}
                layout={layout}
            />
        ),
    };

    return (routeComponents[first || "dashboard"] ?? (() => (
        <UnknownRoute labels={labels} layout={layout} />
    )))();
}
