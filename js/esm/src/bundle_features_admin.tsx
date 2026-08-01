// This file is part of Moodle and is licensed under the
// GNU General Public License, version 3 or later.
//
// You may redistribute and modify it under the terms of the GPL.
// See the plugin root LICENSE file for complete terms.

/**
 * React admin bundle advanced/merchandising features editor for Modern Commerce.
 *
 * @module     local_moderncommerce/bundle_features_admin
 * @copyright  2025 Adebare Showemimo | adebareshowemimo@gmail.com | support@agunfoninteractivity.com | www.agunfoninteractivity.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {useEffect, useState} from "react";
import {mcClasses, toast, useModernCommerceClassSync} from "./design_system";
import {McButton} from "./button";

declare const M: {
    cfg: {
        sesskey: string;
        wwwroot: string;
    };
};

type Labels = Record<string, string>;
type Option = {value: string; label: string};
type Course = {id: number; fullname: string};
type BoolChange = (value: boolean) => void;

type Detail = {
    bundleid: number;
    bundlename: string;
    isprogram: boolean;
    typelabel: string;
    imageurl: string;
    hasimage: boolean;
    displayprice: string;
    displaysaleprice: string;
    onsale: boolean;
    coursescount: number;
    totaldurationminutes: number;
    kpiassessments: number;
    savingsamount: number;
    savingspercent: number;
    displaysavings: string;
    price: number;
    compareat: number;
    visibility: string;
    availstart: string;
    availend: string;
    featured: boolean;
    bestseller: boolean;
    trending: boolean;
    skilllevel: string;
    language: string;
    hasprereq: boolean;
    autoduration: boolean;
    durationhours: number;
    durationminutes: number;
    autoassessments: boolean;
    assessmentscount: number;
    autooutline: boolean;
    passgrade: number;
    passpolicy: string;
    certenabled: boolean;
    outline: Array<{title: string}>;
    mustpassids: number[];
    courses: Course[];
    prereqcourses: Course[];
    tags: string[];
    levels: Option[];
    languages: Option[];
    visibilities: Option[];
    passpolicies: Option[];
};

type Form = {
    price: string;
    compareat: string;
    visibility: string;
    availstart: string;
    availend: string;
    featured: boolean;
    bestseller: boolean;
    trending: boolean;
    skilllevel: string;
    language: string;
    autoduration: boolean;
    durationhours: string;
    durationminutes: string;
    autoassessments: boolean;
    assessmentscount: string;
    passgrade: string;
    passpolicy: string;
    certenabled: boolean;
    outline: string[];
    mustpassids: number[];
    tags: string[];
};

type SaveResponse = {success: boolean; message: string};
type Props = {bundleId: number; getMethodName: string; saveMethodName: string; labels: Labels};

const formatDuration = (minutes: number): string => {
    const total = Math.max(0, Math.round(minutes));
    const hours = Math.floor(total / 60);
    const mins = total % 60;
    if (hours > 0 && mins > 0) {
        return `${hours}h ${mins}m`;
    }
    if (hours > 0) {
        return `${hours}h`;
    }
    return `${mins}m`;
};

const callMoodleService = async <T, >(methodName: string, args: unknown): Promise<T> => {
    const url = `${M.cfg.wwwroot}/lib/ajax/service.php?sesskey=${encodeURIComponent(M.cfg.sesskey)}&info=${encodeURIComponent(methodName)}`;
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

const detailToForm = (d: Detail): Form => ({
    price: d.price > 0 ? String(d.price) : "",
    compareat: d.compareat > 0 ? String(d.compareat) : "",
    visibility: d.visibility,
    availstart: d.availstart,
    availend: d.availend,
    featured: d.featured,
    bestseller: d.bestseller,
    trending: d.trending,
    skilllevel: d.skilllevel,
    language: d.language,
    autoduration: d.autoduration,
    durationhours: String(d.durationhours),
    durationminutes: String(d.durationminutes),
    autoassessments: d.autoassessments,
    assessmentscount: String(d.assessmentscount),
    passgrade: String(d.passgrade),
    passpolicy: d.passpolicy,
    certenabled: d.certenabled,
    outline: d.outline.length > 0 ? d.outline.map((o) => o.title) : [""],
    mustpassids: d.mustpassids,
    tags: d.tags,
});

export default function BundleFeaturesAdmin({bundleId, getMethodName, saveMethodName, labels}: Props) {
    useModernCommerceClassSync();
    const [detail, setDetail] = useState<Detail | null>(null);
    const [form, setForm] = useState<Form | null>(null);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState("");
    const [tagInput, setTagInput] = useState("");

    useEffect(() => {
        let cancelled = false;
        setLoading(true);
        setError("");
        void callMoodleService<Detail>(getMethodName, {bundleid: bundleId})
            .then((result) => {
                if (!cancelled) {
                    setDetail(result);
                    setForm(detailToForm(result));
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
    }, [getMethodName, bundleId]);

    const update = (changes: Partial<Form>) => setForm((c) => c ? {...c, ...changes} : c);

    const toggleMustPass = (courseid: number) => {
        setForm((c) => {
            if (!c) {
                return c;
            }
            const has = c.mustpassids.includes(courseid);
            return {...c, mustpassids: has ? c.mustpassids.filter((id) => id !== courseid) : [...c.mustpassids, courseid]};
        });
    };

    const moveOutline = (index: number, delta: number) => {
        setForm((c) => {
            if (!c) {
                return c;
            }
            const next = [...c.outline];
            const target = index + delta;
            if (target < 0 || target >= next.length) {
                return c;
            }
            [next[index], next[target]] = [next[target], next[index]];
            return {...c, outline: next};
        });
    };

    const addTag = () => {
        const tag = tagInput.trim();
        if (tag === "" || !form || form.tags.includes(tag)) {
            setTagInput("");
            return;
        }
        update({tags: [...form.tags, tag]});
        setTagInput("");
    };

    const submit = async() => {
        if (!form) {
            return;
        }
        setSaving(true);
        setError("");
        try {
            const result = await callMoodleService<SaveResponse>(saveMethodName, {
                bundleid: bundleId,
                // Price is owned by the bundle editor drawer (save_bundle); pass the stored
                // merchandising values through unchanged so this form never clobbers them.
                price: Number(form.price) || 0,
                compareat: Number(form.compareat) || 0,
                visibility: form.visibility,
                availstart: form.availstart,
                availend: form.availend,
                featured: form.featured,
                bestseller: form.bestseller,
                trending: form.trending,
                skilllevel: form.skilllevel,
                language: form.language,
                hasprereq: (detail?.prereqcourses.length ?? 0) > 0,
                autoduration: form.autoduration,
                durationhours: Number(form.durationhours) || 0,
                durationminutes: Number(form.durationminutes) || 0,
                autoassessments: form.autoassessments,
                assessmentscount: Number(form.assessmentscount) || 0,
                autooutline: detail?.autooutline ?? true,
                passgrade: Number(form.passgrade) || 0,
                passpolicy: form.passpolicy,
                certenabled: form.certenabled,
                outline: form.outline.map((t) => t.trim()).filter((t) => t !== "").map((title) => ({title})),
                mustpassids: form.mustpassids,
                tags: form.tags,
            });
            if (!result.success) {
                toast.error(result.message);
            } else {
                toast.success(result.message);
            }
        } catch (caught) {
            toast.error(caught instanceof Error ? caught.message : String(caught));
        } finally {
            setSaving(false);
        }
    };

    const renderSwitch = (checked: boolean, onChange: BoolChange, label: string) => (
        <label className={mcClasses("mc-switch")}>
            <input checked={checked} onChange={(event) => onChange(event.target.checked)} type="checkbox" />
            <span className={mcClasses("mc-switch__track")} aria-hidden="true" />
            <span className={mcClasses("mc-switch__thumb")} aria-hidden="true" />
            <span className={mcClasses("mc-switch__label")}>{label}</span>
        </label>
    );

    if (loading && !form) {
        return <div className={mcClasses("mc-product-admin__loading")}>{labels.loading}</div>;
    }
    if (error && !form) {
        return (
            <div className={mcClasses("mc-alert mc-alert--danger")} role="alert">
                <i className="bi bi-exclamation-triangle mc-alert__icon" aria-hidden="true" />
                <div className={mcClasses("mc-alert__body")}>{error}</div>
            </div>
        );
    }
    if (!form || !detail) {
        return null;
    }

    const durationMinutes = form.autoduration
        ? detail.totaldurationminutes
        : (Number(form.durationhours) || 0) * 60 + (Number(form.durationminutes) || 0);
    const assessmentsValue = form.autoassessments ? detail.kpiassessments : (Number(form.assessmentscount) || 0);

    return (
        <div className={mcClasses("mc-bundle-features-layout")}>
            <aside className={mcClasses("mc-bundle-overview")} aria-label={labels.overview}>
                <div className={mcClasses("mc-bundle-overview__head")}>
                    <h4 className={mcClasses("mc-form-section__title")}>{labels.overview}</h4>
                    <div className={mcClasses("mc-field-help")}>{labels.overviewdesc}</div>
                </div>
                <div className={mcClasses("mc-bundle-overview__image")}>
                    {detail.imageurl
                        ? <img src={detail.imageurl} alt="" />
                        : <span className={mcClasses("mc-bundle-overview__noimage")}><i className="bi bi-image" aria-hidden="true" /></span>}
                </div>
                <div className={mcClasses("mc-bundle-overview__name")}>{detail.bundlename}</div>
                <div className={mcClasses("mc-bundle-overview__type")}>{detail.typelabel}</div>

                <div className={mcClasses("mc-bundle-overview__price")}>
                    {detail.onsale ? (
                        <span className={mcClasses("mc-price")}>
                            <span className={mcClasses("mc-price__original")}>{detail.displayprice}</span>
                            <span className={mcClasses("mc-price__main")}>{detail.displaysaleprice}</span>
                        </span>
                    ) : (
                        <span className={mcClasses("mc-price")}>
                            <span className={mcClasses("mc-price__main")}>{detail.displayprice}</span>
                        </span>
                    )}
                    <div className={mcClasses("mc-field-help")}>{labels.overviewmanaged}</div>
                </div>

                {form.tags.length > 0 && (
                    <div className={mcClasses("mc-overview-tags")}>
                        {form.tags.map((tag) => (
                            <span className={mcClasses("mc-badge mc-badge--neutral")} key={tag}>{tag}</span>
                        ))}
                    </div>
                )}

                <div className={mcClasses("mc-overview-kpis")}>
                    <div className={mcClasses("mc-overview-kpi")}>
                        <div className={mcClasses("mc-overview-kpi__value")}>{detail.coursescount}</div>
                        <div className={mcClasses("mc-overview-kpi__label")}>{labels.coursesinbundle}</div>
                    </div>
                    <div className={mcClasses("mc-overview-kpi")}>
                        <div className={mcClasses("mc-overview-kpi__value")}>{detail.displaysavings}</div>
                        <div className={mcClasses("mc-overview-kpi__label")}>{labels.savings}{detail.savingspercent > 0 ? ` (${detail.savingspercent}%)` : ""}</div>
                    </div>
                    <div className={mcClasses("mc-overview-kpi")}>
                        <div className={mcClasses("mc-overview-kpi__value")}>{formatDuration(durationMinutes)}</div>
                        <div className={mcClasses("mc-overview-kpi__label")}>{labels.totalduration}</div>
                    </div>
                    <div className={mcClasses("mc-overview-kpi")}>
                        <div className={mcClasses("mc-overview-kpi__value")}>{assessmentsValue}</div>
                        <div className={mcClasses("mc-overview-kpi__label")}>{labels.assessments}</div>
                    </div>
                </div>

                <div className={mcClasses("mc-overview-pills")}>
                    <span className={mcClasses("mc-badge mc-badge--neutral")}>{form.visibility}</span>
                    <span className={mcClasses("mc-badge mc-badge--neutral")}>
                        {detail.isprogram ? labels.pathordered : labels.pathflexible}
                    </span>
                    <span className={mcClasses(form.certenabled ? "mc-badge mc-badge--success" : "mc-badge mc-badge--neutral")}>
                        {labels.certificate}: {form.certenabled ? labels.enabled : labels.disabled}
                    </span>
                </div>

                <div className={mcClasses("mc-bundle-overview__tip")}>
                    <div className={mcClasses("mc-bundle-overview__tip-title")}>
                        <i className="bi bi-stars" aria-hidden="true" /> {labels.merchandisingready}
                    </div>
                    <div className={mcClasses("mc-field-help")}>{labels.merchandisingtip}</div>
                </div>
            </aside>

            <section className={mcClasses("mc-bundle-features")} aria-label={detail.bundlename}>
            {error && (
                <div className={mcClasses("mc-alert mc-alert--danger")} role="alert">
                    <i className="bi bi-exclamation-triangle mc-alert__icon" aria-hidden="true" />
                    <div className={mcClasses("mc-alert__body")}>{error}</div>
                </div>
            )}

            <div className={mcClasses("mc-form-section")}>
                <div className={mcClasses("mc-form-section__header")}>
                    <h4 className={mcClasses("mc-form-section__title")}>{labels.bundleinfo}</h4>
                </div>
                <div className={mcClasses("mc-form-section__body")}>
                    <div className={mcClasses("mc-product-form__grid")}>
                        <label>
                            <span>{labels.skilllevel}</span>
                            <select className={mcClasses("mc-select")} onChange={(e) => update({skilllevel: e.target.value})} value={form.skilllevel}>
                                <option value="">{labels.none}</option>
                                {detail.levels.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                            </select>
                        </label>
                        <label>
                            <span>{labels.language}</span>
                            <select className={mcClasses("mc-select")} onChange={(e) => update({language: e.target.value})} value={form.language}>
                                <option value="">{labels.none}</option>
                                {detail.languages.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                            </select>
                        </label>
                        <label>
                            <span>{labels.visibility}</span>
                            <select className={mcClasses("mc-select")} onChange={(e) => update({visibility: e.target.value})} value={form.visibility}>
                                {detail.visibilities.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                            </select>
                        </label>
                        <label>
                            <span>{labels.startdate}</span>
                            <input className={mcClasses("mc-form-control")} onChange={(e) => update({availstart: e.target.value})} type="date" value={form.availstart} />
                        </label>
                        <label>
                            <span>{labels.enddate}</span>
                            <input className={mcClasses("mc-form-control")} onChange={(e) => update({availend: e.target.value})} type="date" value={form.availend} />
                        </label>
                    </div>
                </div>
            </div>

            <div className={mcClasses("mc-form-section")}>
                <div className={mcClasses("mc-form-section__header")}>
                    <h4 className={mcClasses("mc-form-section__title")}>{labels.durationassessments}</h4>
                </div>
                <div className={mcClasses("mc-form-section__body")}>
                    <div className={mcClasses("mc-product-form__checks")}>
                        {renderSwitch(form.autoduration, (value) => update({autoduration: value}), `${labels.duration}: ${labels.autodetect}`)}
                    </div>
                    {!form.autoduration && (
                        <div className={mcClasses("mc-product-form__grid mt-3")}>
                            <label><span>{labels.hours}</span><input className={mcClasses("mc-form-control")} min="0" onChange={(e) => update({durationhours: e.target.value})} type="number" value={form.durationhours} /></label>
                            <label><span>{labels.minutes}</span><input className={mcClasses("mc-form-control")} min="0" max="59" onChange={(e) => update({durationminutes: e.target.value})} type="number" value={form.durationminutes} /></label>
                        </div>
                    )}
                    <div className={mcClasses("mc-product-form__checks mt-3")}>
                        {renderSwitch(form.autoassessments, (value) => update({autoassessments: value}), `${labels.assessments}: ${labels.autodetect}`)}
                    </div>
                    {!form.autoassessments && (
                        <div className={mcClasses("mc-product-form__grid mt-3")}>
                            <label><span>{labels.assessments}</span><input className={mcClasses("mc-form-control")} min="0" onChange={(e) => update({assessmentscount: e.target.value})} type="number" value={form.assessmentscount} /></label>
                        </div>
                    )}
                </div>
            </div>

            <div className={mcClasses("mc-form-section")}>
                <div className={mcClasses("mc-form-section__header")}>
                    <h4 className={mcClasses("mc-form-section__title")}>{labels.completionsettings}</h4>
                </div>
                <div className={mcClasses("mc-form-section__body")}>
                    <div className={mcClasses("mc-product-form__grid")}>
                        <label>
                            <span>{labels.passpolicy}</span>
                            <select className={mcClasses("mc-select")} onChange={(e) => update({passpolicy: e.target.value})} value={form.passpolicy}>
                                {detail.passpolicies.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                            </select>
                        </label>
                        <label>
                            <span>{labels.passgrade}</span>
                            <input className={mcClasses("mc-form-control")} min="0" max="100" onChange={(e) => update({passgrade: e.target.value})} step="0.01" type="number" value={form.passgrade} />
                        </label>
                    </div>
                    <div className={mcClasses("mc-product-form__checks mt-3")}>
                        {renderSwitch(form.certenabled, (value) => update({certenabled: value}), labels.enablecertificate)}
                    </div>
                    <div className={mcClasses("mc-field mt-3")}>
                        <span className={mcClasses("mc-field-label")}>{labels.mustpasscourses}</span>
                        <div className={mcClasses("mc-product-form__checks mc-mustpass-grid")}>
                            {detail.courses.length === 0 && <span className={mcClasses("mc-cell-muted")}>-</span>}
                            {detail.courses.map((course) => (
                                <label key={course.id}>
                                    <input checked={form.mustpassids.includes(course.id)} onChange={() => toggleMustPass(course.id)} type="checkbox" />
                                    <span>{course.fullname}</span>
                                </label>
                            ))}
                        </div>
                    </div>
                </div>
            </div>

            <div className={mcClasses("mc-form-section")}>
                <div className={mcClasses("mc-form-section__header d-flex justify-content-between align-items-center gap-2")}>
                    <h4 className={mcClasses("mc-form-section__title")}>{labels.courseoutline}</h4>
                    <button className={mcClasses("mc-button mc-btn-soft")} onClick={() => update({outline: [...form.outline, ""]})} type="button">{labels.addsection}</button>
                </div>
                <div className={mcClasses("mc-form-section__body")}>
                    {form.outline.map((title, index) => (
                        <div className="d-flex gap-2 mb-2" key={index}>
                            <input
                                className={mcClasses("mc-form-control")}
                                onChange={(e) => update({outline: form.outline.map((o, i) => i === index ? e.target.value : o)})}
                                placeholder={labels.sectiontitle}
                                type="text"
                                value={title}
                            />
                            <button aria-label={labels.reorder} className={mcClasses("mc-button mc-btn-soft")} disabled={index === 0} onClick={() => moveOutline(index, -1)} type="button">&uarr;</button>
                            <button aria-label={labels.reorder} className={mcClasses("mc-button mc-btn-soft")} disabled={index === form.outline.length - 1} onClick={() => moveOutline(index, 1)} type="button">&darr;</button>
                            <button className={mcClasses("mc-button btn-mc-danger")} onClick={() => update({outline: form.outline.filter((_, i) => i !== index)})} type="button">&times;</button>
                        </div>
                    ))}
                </div>
            </div>

            <div className={mcClasses("mc-form-section")}>
                <div className={mcClasses("mc-form-section__header")}>
                    <h4 className={mcClasses("mc-form-section__title")}>{labels.tags}</h4>
                </div>
                <div className={mcClasses("mc-form-section__body")}>
                    {form.tags.length > 0 && (
                        <div className="d-flex flex-wrap gap-1 mb-2">
                            {form.tags.map((tag) => (
                                <span className={mcClasses("mc-badge mc-badge--neutral")} key={tag}>
                                    {tag}
                                    <button aria-label={labels.remove} className={mcClasses("mc-button mc-btn-ghost p-0 ms-1 mc-tag-remove")} onClick={() => update({tags: form.tags.filter((t) => t !== tag)})} type="button">&times;</button>
                                </span>
                            ))}
                        </div>
                    )}
                    <div className="d-flex gap-2">
                        <input
                            className={mcClasses("mc-form-control")}
                            onChange={(e) => setTagInput(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === "Enter") {
                                    e.preventDefault();
                                    addTag();
                                }
                            }}
                            placeholder={labels.addtag}
                            type="text"
                            value={tagInput}
                        />
                        <button className={mcClasses("mc-button mc-btn-soft text-nowrap")} onClick={addTag} type="button">{labels.addtag}</button>
                    </div>
                </div>
            </div>

            <div className={mcClasses("mc-form-section")}>
                <div className={mcClasses("mc-form-section__header")}>
                    <h4 className={mcClasses("mc-form-section__title")}>{labels.badges}</h4>
                </div>
                <div className={mcClasses("mc-form-section__body")}>
                    <div className={mcClasses("mc-product-form__checks")}>
                        {renderSwitch(form.featured, (value) => update({featured: value}), labels.featured)}
                        {renderSwitch(form.bestseller, (value) => update({bestseller: value}), labels.bestseller)}
                        {renderSwitch(form.trending, (value) => update({trending: value}), labels.trending)}
                    </div>
                </div>
            </div>

            <div className={mcClasses("mc-product-form__footer")}>
                <McButton className={mcClasses("btn-mc-primary")} loading={saving} loadingLabel={labels.saving || "Saving..."} onClick={submit} type="button">{labels.save}</McButton>
            </div>
            </section>
        </div>
    );
}
