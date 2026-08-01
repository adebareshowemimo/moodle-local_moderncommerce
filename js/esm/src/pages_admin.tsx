// This file is part of Moodle and is licensed under the
// GNU General Public License, version 3 or later.
//
// You may redistribute and modify it under the terms of the GPL.
// See the plugin root LICENSE file for complete terms.

/**
 * React admin manager for Modern Commerce public store pages.
 *
 * @module     local_moderncommerce/pages_admin
 * @copyright  2025 Adebare Showemimo | adebareshowemimo@gmail.com | support@agunfoninteractivity.com | www.agunfoninteractivity.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {useEffect, useState} from "react";
import {mcClasses, toast, useModernCommerceClassSync} from "./design_system";
import {McBadge} from "./badge";
import {McButton} from "./button";
import {McDrawer} from "./drawer";
import {McTableActionMenu, McTableCard, McTableFooter} from "./table_components";

declare const M: {
    cfg: {
        sesskey: string;
        wwwroot: string;
    };
};

type Labels = Record<string, string>;

type Methods = {
    getLayout: string;
    saveLayout: string;
    togglePage: string;
};

type PageConfig = {
    key: string;
    title: string;
    summary: string;
    icon: string;
    url: string;
    enabled: boolean;
    required: boolean;
};

type Zone = {
    slug: string;
    label: string;
};

type Widget = {
    id: number;
    type: string;
    typelabel: string;
    zone: string;
    enabled: boolean;
    sortorder: number;
    title: string;
};

type LayoutResponse = {
    widgets: Widget[];
    zones: Zone[];
};

type SaveResponse = {
    success: boolean;
    message: string;
};

type TogglePageResponse = {
    success: boolean;
    message: string;
    page: {
        key: string;
        enabled: boolean;
    };
};

type Props = {
    pages: PageConfig[];
    methods: Methods;
    globalUrl: string;
    labels: Labels;
};

const callMoodleService = async <T, >(methodName: string, args: unknown): Promise<T> => {
    const url = `${M.cfg.wwwroot}/lib/ajax/service.php`
        + `?sesskey=${encodeURIComponent(M.cfg.sesskey)}`
        + `&info=${encodeURIComponent(methodName)}`;
    const response = await fetch(url, {
        method: "POST",
        credentials: "same-origin",
        headers: {"Content-Type": "application/json"},
        body: JSON.stringify([{index: 0, methodname: methodName, args}]),
    });

    if (!response.ok) {
        throw new Error(`${response.status} ${response.statusText}`);
    }

    const payload = await response.json();
    const first = Array.isArray(payload) ? payload[0] : payload;
    if (!first) {
        throw new Error("Empty Moodle service response.");
    }
    if (first.error) {
        const exception = first.exception ?? {};
        throw new Error(exception.message ?? first.message ?? "Moodle service request failed.");
    }

    return (first.data ?? first) as T;
};

const formatCount = (value: number): string => new Intl.NumberFormat(document.documentElement.lang || undefined).format(value);

export default function PagesAdmin({pages, methods, globalUrl, labels}: Props) {
    useModernCommerceClassSync();

    const [pageRows, setPageRows] = useState<PageConfig[]>(pages);
    const [drawerOpen, setDrawerOpen] = useState(false);
    const [selectedPage, setSelectedPage] = useState<PageConfig | null>(null);
    const [layout, setLayout] = useState<LayoutResponse | null>(null);
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [busyPage, setBusyPage] = useState("");
    const [error, setError] = useState("");

    useEffect(() => {
        setPageRows(pages);
    }, [pages]);

    const openDrawer = (page: PageConfig) => {
        setSelectedPage(page);
        setDrawerOpen(true);
        setLayout(null);
        setError("");
        setLoading(true);

        void callMoodleService<LayoutResponse>(methods.getLayout, {page: page.key})
            .then((result) => setLayout({
                zones: result.zones ?? [],
                widgets: result.widgets ?? [],
            }))
            .catch((caught: unknown) => setError(caught instanceof Error ? caught.message : String(caught)))
            .finally(() => setLoading(false));
    };

    const closeDrawer = () => {
        if (saving) {
            return;
        }
        setDrawerOpen(false);
    };

    const zoneWidgets = (zone: string): Widget[] => {
        return (layout?.widgets ?? []).filter((widget) => widget.zone === zone);
    };

    const swapWithinZone = (widgetId: number, direction: number) => {
        if (!layout) {
            return;
        }

        const widget = layout.widgets.find((row) => row.id === widgetId);
        if (!widget) {
            return;
        }

        const zoneIndexes = layout.widgets
            .map((row, index) => row.zone === widget.zone ? index : -1)
            .filter((index) => index >= 0);
        const currentPosition = zoneIndexes.findIndex((index) => layout.widgets[index].id === widgetId);
        const targetPosition = currentPosition + direction;
        if (currentPosition < 0 || targetPosition < 0 || targetPosition >= zoneIndexes.length) {
            return;
        }

        const nextWidgets = [...layout.widgets];
        const currentIndex = zoneIndexes[currentPosition];
        const targetIndex = zoneIndexes[targetPosition];
        [nextWidgets[currentIndex], nextWidgets[targetIndex]] = [nextWidgets[targetIndex], nextWidgets[currentIndex]];
        setLayout({...layout, widgets: nextWidgets});
    };

    const toggleWidget = (widgetId: number, enabled: boolean) => {
        if (!layout) {
            return;
        }
        setLayout({
            ...layout,
            widgets: layout.widgets.map((widget) => widget.id === widgetId ? {...widget, enabled} : widget),
        });
    };

    const saveLayout = async() => {
        if (!layout || !selectedPage) {
            return;
        }

        const zoneCounters: Record<string, number> = {};
        const items = layout.widgets.map((widget) => {
            const sortorder = zoneCounters[widget.zone] ?? 0;
            zoneCounters[widget.zone] = sortorder + 1;

            return {
                id: widget.id,
                zone: widget.zone,
                enabled: widget.enabled,
                sortorder,
            };
        });

        setSaving(true);
        setError("");

        try {
            const result = await callMoodleService<SaveResponse>(methods.saveLayout, {
                page: selectedPage.key,
                items,
            });
            if (!result.success) {
                setError(result.message);
                return;
            }
            toast.success(result.message);
            const refreshed = await callMoodleService<LayoutResponse>(methods.getLayout, {page: selectedPage.key});
            setLayout({
                zones: refreshed.zones ?? [],
                widgets: refreshed.widgets ?? [],
            });
        } catch (caught) {
            setError(caught instanceof Error ? caught.message : String(caught));
        } finally {
            setSaving(false);
        }
    };

    const togglePage = async(page: PageConfig) => {
        if (page.required) {
            return;
        }

        setBusyPage(page.key);
        setError("");

        try {
            const result = await callMoodleService<TogglePageResponse>(methods.togglePage, {
                page: page.key,
                enabled: !page.enabled,
            });
            if (!result.success) {
                setError(result.message);
                return;
            }

            setPageRows((current) => current.map((row) => row.key === result.page.key
                ? {...row, enabled: result.page.enabled}
                : row));
            toast.success(result.message);
        } catch (caught) {
            setError(caught instanceof Error ? caught.message : String(caught));
        } finally {
            setBusyPage("");
        }
    };

    const renderDrawer = () => {
        if (!drawerOpen || !selectedPage) {
            return null;
        }

        return (
            <McDrawer
                title={selectedPage.title}
                subtitle={labels.pagewidgets}
                onClose={closeDrawer}
                closeLabel={labels.close}
                disableClose={saving}
                backdropClassName="mc-drawer-backdrop--open"
                footer={(
                    <>
                        <McButton
                            className={mcClasses("btn-mc-primary")}
                            disabled={loading || !layout}
                            loading={saving}
                            loadingLabel={labels.saving || "Saving..."}
                            onClick={saveLayout}
                            type="button"
                        >
                            {labels.savechanges}
                        </McButton>
                        <button
                            className={mcClasses("mc-button btn-mc-secondary")}
                            disabled={saving}
                            onClick={closeDrawer}
                            type="button"
                        >
                            {labels.cancel}
                        </button>
                    </>
                )}
            >
                        {error !== "" && (
                            <div className={mcClasses("mc-alert mc-alert--danger")} role="alert">
                                <i className="bi bi-exclamation-triangle mc-alert__icon" aria-hidden="true" />
                                <div className={mcClasses("mc-alert__body")}>{error}</div>
                            </div>
                        )}

                        {loading && <div className={mcClasses("mc-product-admin__loading")}>{labels.loading}</div>}

                        {!loading && layout && layout.widgets.length === 0 && (
                            <div className={mcClasses("mc-empty mc-empty--centered")}>
                                <span className={mcClasses("mc-empty__icon")}><i className="bi bi-layout-sidebar" aria-hidden="true" /></span>
                                <p className={mcClasses("mc-empty__title")}>{labels.nowidgetsassigned}</p>
                            </div>
                        )}

                        {!loading && layout && layout.zones.map((zone) => {
                            const widgets = zoneWidgets(zone.slug);
                            if (widgets.length === 0) {
                                return null;
                            }

                            return (
                                <section className={mcClasses("mc-page-layout-zone")} key={zone.slug}>
                                    <h3 className={mcClasses("mc-sf-section__title")}>{zone.label}</h3>
                                    {widgets.map((widget, index) => (
                                        <div
                                            className={mcClasses(widget.enabled
                                                ? "mc-sf-row mc-page-layout-row"
                                                : "mc-sf-row mc-page-layout-row mc-sf-row--off")}
                                            key={widget.id}
                                        >
                                            <div className={mcClasses("mc-sf-row__reorder")}>
                                                <button
                                                    aria-label={labels.moveup}
                                                    className={mcClasses("mc-button mc-btn-soft")}
                                                    data-mc-button="soft"
                                                    data-mc-button-size="icon"
                                                    disabled={index === 0 || saving}
                                                    onClick={() => swapWithinZone(widget.id, -1)}
                                                    title={labels.moveup}
                                                    type="button"
                                                >
                                                    <i className="bi bi-chevron-up" aria-hidden="true" />
                                                </button>
                                                <button
                                                    aria-label={labels.movedown}
                                                    className={mcClasses("mc-button mc-btn-soft")}
                                                    data-mc-button="soft"
                                                    data-mc-button-size="icon"
                                                    disabled={index === widgets.length - 1 || saving}
                                                    onClick={() => swapWithinZone(widget.id, 1)}
                                                    title={labels.movedown}
                                                    type="button"
                                                >
                                                    <i className="bi bi-chevron-down" aria-hidden="true" />
                                                </button>
                                            </div>
                                            <label className={mcClasses("mc-page-layout-toggle")} title={labels.show}>
                                                <input
                                                    checked={widget.enabled}
                                                    disabled={saving}
                                                    onChange={(event) => toggleWidget(widget.id, event.target.checked)}
                                                    type="checkbox"
                                                />
                                                {labels.show}
                                            </label>
                                            <span className={mcClasses("mc-sf-row__title")}>
                                                {widget.title || widget.typelabel || widget.type}
                                                <span className={mcClasses("mc-sf-row__type")}> / {widget.typelabel || widget.type}</span>
                                            </span>
                                        </div>
                                    ))}
                                </section>
                            );
                        })}
            </McDrawer>
        );
    };

    return (
        <section className={mcClasses("mc-product-admin")} aria-label={labels.title}>
            {renderDrawer()}

            {error !== "" && (
                <div className={mcClasses("mc-alert mc-alert--danger")} role="alert">
                    <i className="bi bi-exclamation-triangle mc-alert__icon" aria-hidden="true" />
                    <div className={mcClasses("mc-alert__body")}>{error}</div>
                </div>
            )}

            <McTableCard
                title={(
                    <div>
                        <h3 className={mcClasses("mc-card-title mb-1")}>{labels.storepages}</h3>
                        <p className={mcClasses("mc-cell-muted small mb-0")}>
                            <i className="bi bi-globe2 me-1" aria-hidden="true" />
                            {labels.storeglobalintro}
                        </p>
                    </div>
                )}
                actions={(
                    <a className={mcClasses("mc-button btn-mc-primary")} href={globalUrl}>
                        <i className="bi bi-sliders" aria-hidden="true" />
                        <span>{labels.storeglobalmanage}</span>
                    </a>
                )}
                footer={(
                    <McTableFooter
                        summary={<span>{labels.showing} {formatCount(pageRows.length)} / {formatCount(pageRows.length)}</span>}
                    />
                )}
            >
                <table className={mcClasses("table mc-table mc-product-table mb-0")} aria-label={labels.storepages}>
                    <thead>
                        <tr>
                            <th scope="col">{labels.page}</th>
                            <th scope="col">{labels.publicurl}</th>
                            <th scope="col">{labels.status}</th>
                            <th scope="col" className="text-end">{labels.actions}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {pageRows.map((page) => (
                            <tr key={page.key}>
                                <td>
                                    <div className="d-flex align-items-center gap-2">
                                        <i className={`bi ${page.icon}`} aria-hidden="true" />
                                        <div>
                                            <div>{page.title}</div>
                                            {page.summary !== "" && <div className={mcClasses("mc-cell-muted small")}>{page.summary}</div>}
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <a href={page.url}>{page.url}</a>
                                </td>
                                <td>
                                    <McBadge variant={page.enabled ? "success" : "neutral"} tone="soft" dot>
                                        {page.required ? labels.requiredpage : (page.enabled ? labels.enabled : labels.disabled)}
                                    </McBadge>
                                </td>
                                <td className="text-end">
                                    <div className={mcClasses("mc-table-design__actions justify-content-end")}>
                                        {!page.required && (
                                            <button
                                                aria-label={`${page.enabled ? labels.disablepage : labels.enablepage}: ${page.title}`}
                                                aria-pressed={page.enabled}
                                                className={mcClasses("mc-button", page.enabled
                                                    ? "mc-page-visibility-switch mc-page-visibility-switch--on"
                                                    : "mc-page-visibility-switch")}
                                                data-mc-button="ghost"
                                                disabled={busyPage === page.key}
                                                onClick={() => togglePage(page)}
                                                title={page.enabled ? labels.disablepage : labels.enablepage}
                                                type="button"
                                            >
                                                <span className={mcClasses("mc-page-visibility-switch__track")} aria-hidden="true">
                                                    <span className={mcClasses("mc-page-visibility-switch__knob")} />
                                                </span>
                                            </button>
                                        )}
                                        <McTableActionMenu
                                            label={`${labels.actions}: ${page.title}`}
                                            items={[
                                                {
                                                    key: "manage",
                                                    label: labels.managewidgets,
                                                    icon: "bi bi-gear",
                                                    onClick: () => openDrawer(page),
                                                },
                                                {
                                                    key: "preview",
                                                    label: labels.preview,
                                                    icon: "bi bi-eye",
                                                    href: page.url,
                                                },
                                            ]}
                                        />
                                    </div>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </McTableCard>
        </section>
    );
}
