// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * React admin enrollment keys manager for Modern Commerce.
 *
 * @module     local_moderncommerce/keys_admin
 * @copyright  2025 Adebare Showemimo | adebareshowemimo@gmail.com | support@agunfoninteractivity.com | www.agunfoninteractivity.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {useEffect, useState} from "react";
import {mcClasses, toast, useModernCommerceClassSync} from "./design_system";
import {McButton} from "./button";
import {McBadge} from "./badge";
import type {McBadgeVariant} from "./badge";
import {McDrawer} from "./drawer";
import {McTableActionMenu, McTableCard, McTableFooter, McTablePagination} from "./table_components";
import {confirmDialog} from "./modal";

declare const M: {
    cfg: {
        sesskey: string;
        wwwroot: string;
    };
};

type Labels = Record<string, string>;

type SelectOption = {
    value: string;
    label: string;
};

type Filters = {
    search: string;
    status: string;
    targettype: string;
    targetid: number;
    page: number;
    perpage: number;
    productkind: string;
};

type Key = {
    id: number;
    keycode: string;
    keytype: string;
    keytypelabel: string;
    status: string;
    statuslabel: string;
    statusclass: string;
    usedcount: number;
    maxuses: number;
    maxusesperuser: number;
    expiryts: number;
    expiry: string;
    created: string;
    createdbyname: string;
    targettype: string;
    targetlabel: string;
    targetname: string;
};

type Stats = {
    total: number;
    active: number;
    used: number;
    expired: number;
};

type ListResponse = {
    items: Key[];
    total: number;
    page: number;
    perpage: number;
    stats: Stats;
};

type GenerateResponse = {
    success: boolean;
    generated: number;
    keycodes: string[];
    message: string;
};

type StatusResponse = {
    success: boolean;
    message: string;
    status: string;
};

type TargetOption = {
    id: number;
    label: string;
};

type GenerateForm = {
    targettype: "course" | "product";
    targetid: number;
    targetname: string;
    query: string;
    quantity: string;
    keytype: "single" | "multi";
    maxuses: string;
    validdays: string;
};

type KeysAdminProps = {
    listMethodName: string;
    generateMethodName: string;
    setStatusMethodName: string;
    searchCoursesMethodName: string;
    listBundlesMethodName: string;
    initialTargetType: string;
    initialTargetId: number;
    initialTargetName: string;
    initialProductKind: string;
    showTableGenerateAction?: boolean;
    statusOptions: SelectOption[];
    perPageOptions: number[];
    labels: Labels;
};

const callMoodleService = async <T, >(methodName: string, args: unknown): Promise<T> => {
    const url = `${M.cfg.wwwroot}/lib/ajax/service.php?sesskey=${encodeURIComponent(M.cfg.sesskey)}&info=${encodeURIComponent(methodName)}`;
    const response = await fetch(url, {
        method: "POST",
        credentials: "same-origin",
        headers: {
            "Content-Type": "application/json",
        },
        body: JSON.stringify([
            {
                index: 0,
                methodname: methodName,
                args,
            },
        ]),
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

const badgeVariant = (variant: string): McBadgeVariant => {
    const variants: McBadgeVariant[] = ["primary", "secondary", "accent", "success", "warning", "danger", "info", "neutral"];
    return variants.includes(variant as McBadgeVariant) ? variant as McBadgeVariant : "neutral";
};

export default function KeysAdmin({
    listMethodName,
    generateMethodName,
    setStatusMethodName,
    searchCoursesMethodName,
    listBundlesMethodName,
    initialTargetType,
    initialTargetId,
    initialTargetName,
    initialProductKind,
    showTableGenerateAction = false,
    statusOptions,
    perPageOptions,
    labels,
}: KeysAdminProps) {
    useModernCommerceClassSync();
    const productKind = initialProductKind === "course" || initialProductKind === "bundle" ? initialProductKind : "";
    const fixedGenerateTargetType = productKind === "bundle" || initialTargetType === "product"
        ? "product"
        : (productKind === "course" || initialTargetType === "course" ? "course" : "");
    const showTargetTypePicker = fixedGenerateTargetType === "";
    const presetType: "course" | "product" = fixedGenerateTargetType === "product" ? "product" : "course";

    const [filters, setFilters] = useState<Filters>({
        search: "",
        status: "",
        targettype: initialTargetType === "course" || initialTargetType === "product" ? initialTargetType : "",
        targetid: initialTargetId > 0 ? initialTargetId : 0,
        page: 0,
        perpage: 10,
        productkind: productKind,
    });
    const [searchInput, setSearchInput] = useState("");
    const [data, setData] = useState<ListResponse | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");
    const [busyId, setBusyId] = useState(0);
    const [reloadToken, setReloadToken] = useState(0);
    const [copied, setCopied] = useState("");

    const [genOpen, setGenOpen] = useState(initialTargetId > 0);
    const [genForm, setGenForm] = useState<GenerateForm>({
        targettype: presetType,
        targetid: initialTargetId > 0 ? initialTargetId : 0,
        targetname: initialTargetName,
        query: "",
        quantity: "1",
        keytype: "single",
        maxuses: "1",
        validdays: "90",
    });
    const [genOptions, setGenOptions] = useState<TargetOption[]>([]);
    const [genGenerated, setGenGenerated] = useState<string[]>([]);

    useEffect(() => {
        const timer = window.setTimeout(() => {
            setFilters((current) => current.search === searchInput ? current : {...current, search: searchInput, page: 0});
        }, 350);

        return () => window.clearTimeout(timer);
    }, [searchInput]);

    useEffect(() => {
        let cancelled = false;
        setLoading(true);
        setError("");

        void callMoodleService<ListResponse>(listMethodName, filters)
            .then((result) => {
                if (!cancelled) {
                    setData(result);
                }
            })
            .catch((caught: unknown) => {
                if (!cancelled) {
                    setError(caught instanceof Error ? caught.message : String(caught));
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
    }, [filters, listMethodName, reloadToken]);

    useEffect(() => {
        const refreshButton = document.getElementById("moderncommerce-keys-refresh");
        const generateButton = document.getElementById("moderncommerce-keys-generate");
        const refresh = () => setReloadToken((current) => current + 1);
        const toggleGenerate = () => {
            setGenOpen((current) => !current);
            setGenGenerated([]);
        };
        refreshButton?.addEventListener("click", refresh);
        generateButton?.addEventListener("click", toggleGenerate);

        return () => {
            refreshButton?.removeEventListener("click", refresh);
            generateButton?.removeEventListener("click", toggleGenerate);
        };
    }, []);

    // Target typeahead for the generate panel.
    useEffect(() => {
        if (!genOpen) {
            return;
        }
        const query = genForm.query.trim();
        if (query.length < 2) {
            setGenOptions([]);
            return;
        }

        let cancelled = false;
        const timer = window.setTimeout(() => {
            if (genForm.targettype === "course") {
                void callMoodleService<{items: Array<{id: number; fullname: string}>}>(searchCoursesMethodName, {query, limit: 20})
                    .then((result) => {
                        if (!cancelled) {
                            setGenOptions(result.items.map((item) => ({id: item.id, label: item.fullname})));
                        }
                    })
                    .catch(() => {
                        if (!cancelled) {
                            setGenOptions([]);
                        }
                    });
            } else {
                void callMoodleService<{items: Array<{id: number; name: string}>}>(listBundlesMethodName,
                    {search: query, status: "", type: "", page: 0, perpage: 10})
                    .then((result) => {
                        if (!cancelled) {
                            setGenOptions(result.items.map((item) => ({id: item.id, label: item.name})));
                        }
                    })
                    .catch(() => {
                        if (!cancelled) {
                            setGenOptions([]);
                        }
                    });
            }
        }, 300);

        return () => {
            cancelled = true;
            window.clearTimeout(timer);
        };
    }, [genForm.query, genForm.targettype, genOpen, searchCoursesMethodName, listBundlesMethodName]);

    const total = data?.total ?? 0;
    const stats = data?.stats;
    const totalPages = Math.max(1, Math.ceil(total / filters.perpage));
    const visibleKeyCount = data?.items.length ?? 0;
    const visibleFrom = total === 0 || visibleKeyCount === 0 ? 0 : (filters.page * filters.perpage) + 1;
    const visibleTo = visibleKeyCount === 0 ? 0 : Math.min(total, visibleFrom + visibleKeyCount - 1);

    const updateFilters = (changes: Partial<Filters>) => {
        setFilters((current) => ({...current, ...changes, page: changes.page ?? 0}));
    };

    const updateGen = (changes: Partial<GenerateForm>) => {
        setGenForm((current) => ({...current, ...changes}));
    };

    const openGenerateDrawer = () => {
        setGenOpen(true);
        setGenGenerated([]);
    };

    const setTargetType = (targettype: "course" | "product") => {
        setGenForm((current) => ({...current, targettype, targetid: 0, targetname: "", query: ""}));
        setGenOptions([]);
    };

    const targetPickerLabel = showTargetTypePicker
        ? labels.selecttarget
        : (genForm.targettype === "course" ? labels.course : labels.bundleorprogram);

    const copyText = (id: string, text: string) => {
        void navigator.clipboard?.writeText(text).then(() => {
            setCopied(id);
            window.setTimeout(() => setCopied(""), 2000);
        });
    };

    const changeStatus = async(key: Key, action: "activate" | "deactivate" | "delete") => {
        if (action === "delete" && !await confirmDialog({message: labels.deleteconfirm, danger: true})) {
            return;
        }

        setBusyId(key.id);
        setError("");

        try {
            const result = await callMoodleService<StatusResponse>(setStatusMethodName, {keyid: key.id, action});
            if (!result.success) {
                setError(result.message);
                return;
            }
            toast.success(result.message);
            setReloadToken((current) => current + 1);
        } catch (caught) {
            setError(caught instanceof Error ? caught.message : String(caught));
        } finally {
            setBusyId(0);
        }
    };

    const renderKeyActions = (key: Key) => (
        <div className={mcClasses("mc-table-design__actions justify-content-end")}>
            <McTableActionMenu
                label={`${labels.actions}: ${key.keycode}`}
                items={[
                    key.status === "active"
                        ? {
                            key: "deactivate",
                            label: labels.deactivate,
                            icon: "bi bi-pause-circle",
                            disabled: busyId === key.id,
                            onClick: () => void changeStatus(key, "deactivate"),
                        }
                        : {
                            key: "activate",
                            label: labels.activate,
                            icon: "bi bi-play-circle",
                            disabled: busyId === key.id,
                            onClick: () => void changeStatus(key, "activate"),
                        },
                    {
                        key: "delete",
                        label: labels.delete,
                        icon: "bi bi-trash",
                        danger: true,
                        disabled: busyId === key.id,
                        onClick: () => void changeStatus(key, "delete"),
                    },
                ]}
            />
        </div>
    );

    const submitGenerate = async() => {
        if (genForm.targetid <= 0) {
            setError(labels.selecttarget);
            return;
        }

        setBusyId(-1);
        setError("");
        setGenGenerated([]);

        try {
            const result = await callMoodleService<GenerateResponse>(generateMethodName, {
                targettype: genForm.targettype,
                targetid: genForm.targetid,
                quantity: Number(genForm.quantity) || 1,
                keytype: genForm.keytype,
                maxuses: Number(genForm.maxuses) || 1,
                validdays: Number(genForm.validdays) || 0,
            });

            if (!result.success) {
                setError(result.message);
                return;
            }

            toast.success(result.message);
            setGenGenerated(result.keycodes);
            setReloadToken((current) => current + 1);
        } catch (caught) {
            setError(caught instanceof Error ? caught.message : String(caught));
        } finally {
            setBusyId(0);
        }
    };

    const visibleKeycodes = (data?.items ?? []).map((key) => key.keycode).join("\n");

    return (
        <section className={mcClasses("mc-product-admin")} aria-label={labels.title}>
            {error && (
                <div className={mcClasses("mc-alert mc-alert--danger")} role="alert">
                    <i className="bi bi-exclamation-triangle mc-alert__icon" aria-hidden="true" />
                    <div className={mcClasses("mc-alert__body")}>{error}</div>
                </div>
            )}

            {genOpen && (
                <McDrawer
                    title={labels.keysettings}
                    onClose={() => setGenOpen(false)}
                    closeLabel={labels.cancel}
                    disableClose={busyId === -1}
                    footer={(
                        <>
                            <McButton
                                className={mcClasses("btn-mc-primary")}
                                loading={busyId === -1}
                                loadingLabel={labels.loading || "Generating..."}
                                onClick={submitGenerate}
                                type="button"
                            >
                                {labels.generate}
                            </McButton>
                            <button
                                className={mcClasses("mc-button btn-mc-secondary")}
                                disabled={busyId === -1}
                                onClick={() => setGenOpen(false)}
                                type="button"
                            >
                                {labels.cancel}
                            </button>
                        </>
                    )}
                >
                    <div className={mcClasses("mc-product-form__section")}>
                        <div className={mcClasses("mc-product-form__grid")}>
                            {showTargetTypePicker && (
                                <label>
                                    <span>{labels.targettype}</span>
                                    <select
                                        className={mcClasses("mc-select")}
                                        onChange={(event) => setTargetType(event.target.value === "product" ? "product" : "course")}
                                        value={genForm.targettype}
                                    >
                                        <option value="course">{labels.course}</option>
                                        <option value="product">{labels.bundleorprogram}</option>
                                    </select>
                                </label>
                            )}
                            <div className={mcClasses("mc-course-picker mc-product-form__wide")}>
                                <label htmlFor="mc-key-target-input">
                                    <span>{targetPickerLabel}</span>
                                    <input
                                        autoComplete="off"
                                        className={mcClasses("mc-form-control")}
                                        id="mc-key-target-input"
                                        onChange={(event) => updateGen({query: event.target.value, targetid: 0, targetname: ""})}
                                        placeholder={genForm.targettype === "course" ? labels.searchcoursesplaceholder : labels.searchbundles}
                                        type="search"
                                        value={genForm.targetid > 0 ? genForm.targetname : genForm.query}
                                    />
                                </label>
                                {genOptions.length > 0 && genForm.targetid <= 0 && (
                                    <div className={mcClasses("mc-course-picker__results")} role="listbox">
                                        {genOptions.map((option) => (
                                            <button
                                                className={mcClasses("mc-button mc-course-picker__option")}
                                                data-mc-button="ghost"
                                                key={option.id}
                                                onClick={() => {
                                                    updateGen({targetid: option.id, targetname: option.label, query: option.label});
                                                    setGenOptions([]);
                                                }}
                                                role="option"
                                                type="button"
                                            >
                                                <span className={mcClasses("mc-course-picker__option-main")}><strong>{option.label}</strong></span>
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </div>
                            <label>
                                <span>{labels.quantity}</span>
                                <input
                                    className={mcClasses("mc-form-control")}
                                    max="500"
                                    min="1"
                                    onChange={(event) => updateGen({quantity: event.target.value})}
                                    type="number"
                                    value={genForm.quantity}
                                />
                            </label>
                            <label>
                                <span>{labels.keytype}</span>
                                <select
                                    className={mcClasses("mc-select")}
                                    onChange={(event) => updateGen({keytype: event.target.value === "multi" ? "multi" : "single"})}
                                    value={genForm.keytype}
                                >
                                    <option value="single">{labels.singleuse}</option>
                                    <option value="multi">{labels.multiuse}</option>
                                </select>
                            </label>
                            {genForm.keytype === "multi" && (
                                <label>
                                    <span>{labels.maxuses}</span>
                                    <input
                                        className={mcClasses("mc-form-control")}
                                        min="0"
                                        onChange={(event) => updateGen({maxuses: event.target.value})}
                                        type="number"
                                        value={genForm.maxuses}
                                    />
                                </label>
                            )}
                            <label>
                                <span>{labels.validdays}</span>
                                <input
                                    className={mcClasses("mc-form-control")}
                                    min="0"
                                    onChange={(event) => updateGen({validdays: event.target.value})}
                                    type="number"
                                    value={genForm.validdays}
                                />
                            </label>
                        </div>
                    </div>

                    {genGenerated.length > 0 && (
                        <div className={mcClasses("mc-product-form__section")}>
                            <div className="d-flex justify-content-between align-items-center">
                                <h4>{labels.generatedkeys} ({genGenerated.length})</h4>
                                <button
                                    className={mcClasses("mc-button mc-btn-soft")}
                                    onClick={() => copyText("generated", genGenerated.join("\n"))}
                                    type="button"
                                >
                                    {copied === "generated" ? labels.copied : labels.exportkeys}
                                </button>
                            </div>
                            <div className="d-flex flex-wrap gap-1 mt-2">
                                {genGenerated.map((code) => (
                                    <McBadge variant="neutral" tone="soft" className="mc-cell-mono" key={code}>{code}</McBadge>
                                ))}
                            </div>
                        </div>
                    )}
                </McDrawer>
            )}

            {stats && (
                <div className={mcClasses("mc-stat-strip")} aria-label={labels.title}>
                    <article className={mcClasses("mc-stat-tile mc-stat-tile--primary")}>
                        <i className="bi bi-key mc-stat-tile__icon" aria-hidden="true" />
                        <div className={mcClasses("mc-stat-tile__body")}>
                            <span className={mcClasses("mc-stat-tile__label")}>{labels.totalkeys}</span>
                            <strong className={mcClasses("mc-stat-tile__value")}>{formatCount(stats.total)}</strong>
                        </div>
                        <i className="bi bi-key-fill mc-stat-tile__watermark" aria-hidden="true" />
                    </article>
                    <article className={mcClasses("mc-stat-tile mc-stat-tile--success")}>
                        <i className="bi bi-check-circle mc-stat-tile__icon" aria-hidden="true" />
                        <div className={mcClasses("mc-stat-tile__body")}>
                            <span className={mcClasses("mc-stat-tile__label")}>{labels.activekeys}</span>
                            <strong className={mcClasses("mc-stat-tile__value")}>{formatCount(stats.active)}</strong>
                        </div>
                        <i className="bi bi-check-circle-fill mc-stat-tile__watermark" aria-hidden="true" />
                    </article>
                    <article className={mcClasses("mc-stat-tile mc-stat-tile--info")}>
                        <i className="bi bi-receipt mc-stat-tile__icon" aria-hidden="true" />
                        <div className={mcClasses("mc-stat-tile__body")}>
                            <span className={mcClasses("mc-stat-tile__label")}>{labels.usedkeys}</span>
                            <strong className={mcClasses("mc-stat-tile__value")}>{formatCount(stats.used)}</strong>
                        </div>
                        <i className="bi bi-receipt mc-stat-tile__watermark" aria-hidden="true" />
                    </article>
                    <article className={mcClasses("mc-stat-tile mc-stat-tile--warning")}>
                        <i className="bi bi-hourglass-bottom mc-stat-tile__icon" aria-hidden="true" />
                        <div className={mcClasses("mc-stat-tile__body")}>
                            <span className={mcClasses("mc-stat-tile__label")}>{labels.expiredkeys}</span>
                            <strong className={mcClasses("mc-stat-tile__value")}>{formatCount(stats.expired)}</strong>
                        </div>
                        <i className="bi bi-hourglass-bottom mc-stat-tile__watermark" aria-hidden="true" />
                    </article>
                </div>
            )}

            <McTableCard
                title={<h2 className={mcClasses("mc-card-title")}>{labels.title}</h2>}
                actions={showTableGenerateAction ? (
                    <McButton
                        className={mcClasses("btn-mc-primary")}
                        onClick={openGenerateDrawer}
                    >
                        <i className="bi bi-key" aria-hidden="true" />
                        <span>{labels.generate}</span>
                    </McButton>
                ) : undefined}
                toolbar={(
                    <div className={mcClasses("mc-toolbar mc-product-toolbar mc-table-design-toolbar")}>
                        <div className={mcClasses("mc-product-toolbar__search")}>
                            <label className={mcClasses("mc-filter-label")} htmlFor="mc-keys-search">
                                {labels.search}
                            </label>
                            <input
                                className={mcClasses("mc-form-control")}
                                id="mc-keys-search"
                                onChange={(event) => setSearchInput(event.target.value)}
                                placeholder={labels.searchplaceholder}
                                type="search"
                                value={searchInput}
                            />
                        </div>
                        {productKind === "" && (
                            <label className={mcClasses("mc-product-toolbar__field")}>
                                <span className={mcClasses("mc-filter-label")}>{labels.targettype}</span>
                                <select
                                    className={mcClasses("mc-select")}
                                    onChange={(event) => updateFilters({targettype: event.target.value, targetid: 0})}
                                    value={filters.targettype}
                                >
                                    <option value="">{labels.alltargets}</option>
                                    <option value="course">{labels.course}</option>
                                    <option value="product">{labels.bundleorprogram}</option>
                                </select>
                            </label>
                        )}
                        <label className={mcClasses("mc-product-toolbar__field")}>
                            <span className={mcClasses("mc-filter-label")}>{labels.status}</span>
                            <select
                                className={mcClasses("mc-select")}
                                onChange={(event) => updateFilters({status: event.target.value})}
                                value={filters.status}
                            >
                                <option value="">{labels.allstatuses}</option>
                                {statusOptions.map((option) => (
                                    <option key={option.value} value={option.value}>{option.label}</option>
                                ))}
                            </select>
                        </label>
                        <label className={mcClasses("mc-product-toolbar__field mc-product-toolbar__field--small")}>
                            <span className={mcClasses("mc-filter-label")}>{labels.perpage}</span>
                            <select
                                className={mcClasses("mc-select")}
                                onChange={(event) => updateFilters({perpage: Number(event.target.value) || 10})}
                                value={filters.perpage}
                            >
                                {perPageOptions.map((option) => (
                                    <option key={option} value={option}>{option}</option>
                                ))}
                            </select>
                        </label>
                    </div>
                )}
                footer={(
                    <McTableFooter>
                        <div className={mcClasses("mc-product-admin__summary mc-table-design-footer-summary")}>
                            <span>
                                {labels.showing} {formatCount(visibleFrom)}-{formatCount(visibleTo)} / {formatCount(total)}
                            </span>
                            {visibleKeycodes !== "" && (
                                <button
                                    className={mcClasses("mc-button mc-btn-soft")}
                                    onClick={() => copyText("all", visibleKeycodes)}
                                    type="button"
                                >
                                    {copied === "all" ? labels.copied : labels.exportkeys}
                                </button>
                            )}
                        </div>
                        <McTablePagination
                            previousLabel={labels.previous}
                            nextLabel={labels.next}
                            pageLabel={labels.page}
                            page={Math.min(filters.page + 1, totalPages)}
                            totalPages={totalPages}
                            previousDisabled={loading || filters.page <= 0}
                            nextDisabled={loading || filters.page + 1 >= totalPages}
                            onPrevious={() => updateFilters({page: Math.max(0, filters.page - 1)})}
                            onNext={() => updateFilters({page: filters.page + 1})}
                        />
                    </McTableFooter>
                )}
            >
                <table className={mcClasses("table mc-table mc-product-table mb-0")} aria-label={labels.title}>
                            <thead>
                                <tr>
                                    <th scope="col">{labels.keycode}</th>
                                    <th scope="col">{labels.target}</th>
                                    <th scope="col">{labels.keytype}</th>
                                    <th scope="col" className="text-end">{labels.uses}</th>
                                    <th scope="col">{labels.status}</th>
                                    <th scope="col">{labels.expiry}</th>
                                    <th scope="col" className="text-end">{labels.actions}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {!loading && data?.items.length === 0 && (
                                    <tr>
                                        <td colSpan={7}>
                                            <div className={mcClasses("mc-empty mc-empty--centered")}>
                                                <span className={mcClasses("mc-empty__icon")}>
                                                    <i className="bi bi-key" aria-hidden="true" />
                                                </span>
                                                <p className={mcClasses("mc-empty__title")}>
                                                    {total === 0 ? labels.nokeys : labels.noresults}
                                                </p>
                                            </div>
                                        </td>
                                    </tr>
                                )}
                                {data?.items.map((key) => (
                                    <tr key={key.id}>
                                        <td>
                                            <button
                                                className={mcClasses("mc-button mc-cell-mono mc-btn-ghost p-0 text-start")}
                                                onClick={() => copyText(`key-${key.id}`, key.keycode)}
                                                title={labels.copy}
                                                type="button"
                                            >
                                                {key.keycode}
                                            </button>
                                            {copied === `key-${key.id}` && (
                                                <span className={mcClasses("mc-cell-muted small ms-1")}>
                                                    {labels.copied}
                                                </span>
                                            )}
                                            <div className={mcClasses("mc-cell-muted small")}>
                                                {key.created} - {key.createdbyname}
                                            </div>
                                        </td>
                                        <td>
                                            <div>{key.targetname}</div>
                                            <div className={mcClasses("mc-cell-muted small")}>{key.targetlabel}</div>
                                        </td>
                                        <td>
                                            <McBadge variant="neutral" tone="soft">
                                                {key.keytypelabel}
                                            </McBadge>
                                        </td>
                                        <td className="text-end">
                                            {key.usedcount}{key.maxuses > 0 ? ` / ${key.maxuses}` : ""}
                                        </td>
                                        <td>
                                            <McBadge variant={badgeVariant(key.statusclass)} tone="soft" dot>
                                                {key.statuslabel}
                                            </McBadge>
                                        </td>
                                        <td className={mcClasses("mc-cell-muted mc-cell-nowrap")}>{key.expiry}</td>
                                        <td className="text-end">
                                            {renderKeyActions(key)}
                                        </td>
                                    </tr>
                                ))}
                                {loading && (
                                    <tr>
                                        <td colSpan={7}>
                                            <div className={mcClasses("mc-product-admin__loading")}>
                                                {labels.loading}
                                            </div>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                </table>
            </McTableCard>
        </section>
    );
}
