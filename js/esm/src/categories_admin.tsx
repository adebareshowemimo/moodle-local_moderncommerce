// This file is part of Moodle and is licensed under the
// GNU General Public License, version 3 or later.
//
// You may redistribute and modify it under the terms of the GPL.
// See the plugin root LICENSE file for complete terms.

/**
 * React admin for managing Modern Commerce product categories.
 *
 * @module     local_moderncommerce/categories_admin
 * @copyright  2025 Adebare Showemimo | adebareshowemimo@gmail.com | support@agunfoninteractivity.com | www.agunfoninteractivity.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {useEffect, useRef, useState} from "react";
import type {FormEvent} from "react";
import {mcClasses, toast, useModernCommerceClassSync} from "./design_system";
import {McBadge} from "./badge";
import {McButton} from "./button";
import {McDrawer} from "./drawer";
import {confirmDialog} from "./modal";
import {McTableActionMenu, McTableCard, McTableFooter} from "./table_components";

declare const M: {
    cfg: {
        sesskey: string;
        wwwroot: string;
    };
};

type Category = {
    id: number;
    name: string;
    slug: string;
    description: string;
    visible: boolean;
    sortorder: number;
    productcount: number;
};

type Methods = {
    list: string;
    save: string;
    delete: string;
    reorder: string;
};

type Labels = Record<string, string>;

type Props = {
    methods: Methods;
    labels: Labels;
};

type CategoryForm = {
    id: number;
    name: string;
    description: string;
    visible: boolean;
};

type ListResponse = {categories: Category[]};
type SaveResponse = {success: boolean; id: number; message: string};
type ReorderResponse = {success: boolean; message: string};

const callMoodleService = async <T, >(methodName: string, args: unknown): Promise<T> => {
    const url = `${M.cfg.wwwroot}/lib/ajax/service.php?sesskey=${encodeURIComponent(M.cfg.sesskey)}`
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

const emptyForm = (): CategoryForm => ({
    id: 0,
    name: "",
    description: "",
    visible: true,
});

export default function CategoriesAdmin({methods, labels}: Props) {
    useModernCommerceClassSync();
    const t = (key: string): string => labels[key] ?? key;

    const [categories, setCategories] = useState<Category[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");
    const [form, setForm] = useState<CategoryForm | null>(null);
    const [saving, setSaving] = useState(false);
    const [formError, setFormError] = useState("");
    const [dragIndex, setDragIndex] = useState<number | null>(null);
    const [dragOverIndex, setDragOverIndex] = useState<number | null>(null);

    const load = () => {
        setLoading(true);
        void callMoodleService<ListResponse>(methods.list, {})
            .then((result) => {
                setCategories(result.categories ?? []);
                setError("");
                return null;
            })
            .catch((caught: unknown) => setError(caught instanceof Error ? caught.message : String(caught)))
            .finally(() => setLoading(false));
    };

    useEffect(() => {
        load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [methods.list]);

    const openNew = () => {
        setForm(emptyForm());
        setFormError("");
    };

    const openEdit = (category: Category) => {
        setForm({
            id: category.id,
            name: category.name,
            description: category.description,
            visible: category.visible,
        });
        setFormError("");
    };

    // Persist a new display order from a drag-and-drop arrangement (optimistic; reverts on failure).
    const persistOrder = (ordered: Category[]) => {
        const previous = categories;
        setCategories(ordered);
        void callMoodleService<ReorderResponse>(methods.reorder, {ids: ordered.map((category) => category.id)})
            .then((result) => {
                if (!result.success) {
                    setCategories(previous);
                    toast.error(result.message);
                }
                return null;
            })
            .catch((caught: unknown) => {
                setCategories(previous);
                toast.error(caught instanceof Error ? caught.message : String(caught));
            });
    };

    const dropOn = (targetIndex: number) => {
        const source = dragIndex;
        setDragIndex(null);
        setDragOverIndex(null);
        if (source === null || source === targetIndex) {
            return;
        }
        const next = [...categories];
        const [moved] = next.splice(source, 1);
        next.splice(targetIndex, 0, moved);
        persistOrder(next);
    };

    const closeForm = () => {
        setForm(null);
        setFormError("");
    };

    // Wire the shell header buttons (rebound through a ref so listeners stay current).
    const handlersRef = useRef({openNew, reload: load});
    handlersRef.current = {openNew, reload: load};
    useEffect(() => {
        const newButton = document.getElementById("moderncommerce-categories-new");
        const refreshButton = document.getElementById("moderncommerce-categories-refresh");
        const onNew = () => handlersRef.current.openNew();
        const onRefresh = () => handlersRef.current.reload();
        newButton?.addEventListener("click", onNew);
        refreshButton?.addEventListener("click", onRefresh);
        return () => {
            newButton?.removeEventListener("click", onNew);
            refreshButton?.removeEventListener("click", onRefresh);
        };
    }, []);

    const submit = async(event: FormEvent) => {
        event.preventDefault();
        if (!form) {
            return;
        }
        if (form.name.trim() === "") {
            setFormError(t("namerequired"));
            return;
        }
        setSaving(true);
        setFormError("");
        try {
            const result = await callMoodleService<SaveResponse>(methods.save, {
                id: form.id,
                name: form.name.trim(),
                description: form.description.trim(),
                visible: form.visible,
            });
            if (!result.success) {
                setFormError(result.message);
                return;
            }
            toast.success(result.message);
            closeForm();
            load();
        } catch (caught) {
            setFormError(caught instanceof Error ? caught.message : String(caught));
        } finally {
            setSaving(false);
        }
    };

    const removeCategory = async(category: Category) => {
        const confirmText = t("deleteconfirm");
        if (!await confirmDialog({
            title: category.name,
            message: confirmText,
            confirmLabel: t("delete"),
            cancelLabel: t("cancel"),
            danger: true,
        })) {
            return;
        }
        try {
            const result = await callMoodleService<SaveResponse>(methods.delete, {id: category.id});
            if (!result.success) {
                toast.error(result.message);
                return;
            }
            toast.success(result.message);
            load();
        } catch (caught) {
            toast.error(caught instanceof Error ? caught.message : String(caught));
        }
    };

    const update = (changes: Partial<CategoryForm>) => setForm((current) => (current ? {...current, ...changes} : current));

    return (
        <section className={mcClasses("mc-product-admin")} aria-label={t("title")}>
            {error && (
                <div className={mcClasses("mc-alert mc-alert--danger")} role="alert">
                    <i className="bi bi-exclamation-triangle mc-alert__icon" aria-hidden="true" />
                    <div className={mcClasses("mc-alert__body")}>{error}</div>
                </div>
            )}

            {form && (
                <McDrawer
                    title={form.id > 0 ? t("editcategory") : t("newcategory")}
                    subtitle={form.id > 0 && form.name ? form.name : undefined}
                    onClose={closeForm}
                    closeLabel={t("close")}
                    disableClose={saving}
                    footer={(
                        <>
                            <McButton
                                className={mcClasses("btn-mc-primary")}
                                disabled={saving}
                                form="mc-category-drawer-form"
                                loading={saving}
                                loadingLabel={t("saving")}
                                type="submit"
                            >
                                {t("save")}
                            </McButton>
                            <button
                                type="button"
                                className={mcClasses("mc-button btn-mc-secondary")}
                                disabled={saving}
                                onClick={closeForm}
                            >
                                {t("cancel")}
                            </button>
                        </>
                    )}
                >
                    {formError && (
                        <div className={mcClasses("mc-alert mc-alert--danger")} role="alert">
                            <i className="bi bi-exclamation-triangle mc-alert__icon" aria-hidden="true" />
                            <div className={mcClasses("mc-alert__body")}>{formError}</div>
                        </div>
                    )}

                    <form id="mc-category-drawer-form" onSubmit={submit}>
                        <div className={mcClasses("mc-form-section")}>
                            <div className={mcClasses("mc-form-section__header")}>
                                <h4 className={mcClasses("mc-form-section__title")}>
                                    {t("categorydetails")}
                                </h4>
                            </div>
                            <div className={mcClasses("mc-form-section__body")}>
                                <div className={mcClasses("mc-product-form__grid")}>
                                    <label>
                                        <span>{t("name")}</span>
                                        <input
                                            autoFocus
                                            className={mcClasses("mc-form-control")}
                                            onChange={(event) => update({name: event.target.value})}
                                            required
                                            type="text"
                                            value={form.name}
                                        />
                                    </label>
                                    <label className={mcClasses("mc-product-form__wide")}>
                                        <span>{t("description")}</span>
                                        <textarea
                                            className={mcClasses("mc-form-control")}
                                            onChange={(event) => update({description: event.target.value})}
                                            rows={3}
                                            value={form.description}
                                        />
                                    </label>
                                </div>
                                <label className={mcClasses("mc-switch mt-2")}>
                                    <input
                                        checked={form.visible}
                                        onChange={(event) => update({visible: event.target.checked})}
                                        type="checkbox"
                                    />
                                    <span className={mcClasses("mc-switch__track")} />
                                    <span className={mcClasses("mc-switch__thumb")} />
                                    <span className={mcClasses("mc-switch__label")}>{t("visible")}</span>
                                </label>
                            </div>
                        </div>
                    </form>
                </McDrawer>
            )}

            {loading ? (
                <div className={mcClasses("mc-product-admin__loading")}>{t("loading")}</div>
            ) : categories.length === 0 ? (
                <div className={mcClasses("mc-card")}>
                    <div className={mcClasses("mc-card-body")}>
                        <div className={mcClasses("mc-empty mc-empty--centered")}>
                            <span className={mcClasses("mc-empty__icon")}>
                                <i className="bi bi-tags" aria-hidden="true" />
                            </span>
                            <p className={mcClasses("mc-empty__title")}>{t("nocategories")}</p>
                            <p className={mcClasses("mc-empty__text")}>
                                {t("nocategoriesdesc")}
                            </p>
                            <button type="button" className={mcClasses("mc-button btn-mc-primary")} onClick={openNew}>
                                <i className="bi bi-plus" aria-hidden="true" />
                                {t("newcategory")}
                            </button>
                        </div>
                    </div>
                </div>
            ) : (
                <McTableCard
                    className={mcClasses("mc-category-table-card")}
                    title={<h2 className={mcClasses("mc-card-title")}>{t("title")}</h2>}
                    actions={(
                        <McButton className={mcClasses("btn-mc-primary")} onClick={openNew} type="button">
                            <i className="bi bi-plus" aria-hidden="true" />
                            {t("newcategory")}
                        </McButton>
                    )}
                    footer={(
                        <McTableFooter
                            summary={(
                                <span>
                                    {t("showing")} 1-{categories.length} / {categories.length}
                                </span>
                            )}
                        />
                    )}
                >
                    <table className={mcClasses("table mc-table mc-product-table mb-0")} aria-label={t("title")}>
                        <thead>
                            <tr>
                                <th scope="col" className="mc-drag-cell">
                                    <span className="visually-hidden">{t("dragtoreorder")}</span>
                                </th>
                                <th scope="col">{t("name")}</th>
                                <th scope="col">{t("slug")}</th>
                                <th scope="col" className="text-center">{t("products")}</th>
                                <th scope="col" className="text-center">{t("visible")}</th>
                                <th scope="col" className="text-end">{t("actions")}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {categories.map((category, index) => (
                                <tr
                                    key={category.id}
                                    draggable={form === null}
                                    onDragStart={(event) => {
                                        setDragIndex(index);
                                        event.dataTransfer.effectAllowed = "move";
                                        event.dataTransfer.setData("text/plain", String(index));
                                    }}
                                    onDragOver={(event) => {
                                        event.preventDefault();
                                        event.dataTransfer.dropEffect = "move";
                                        setDragOverIndex(index);
                                    }}
                                    onDrop={(event) => {
                                        event.preventDefault();
                                        dropOn(index);
                                    }}
                                    onDragEnd={() => {
                                        setDragIndex(null);
                                        setDragOverIndex(null);
                                    }}
                                    className={mcClasses(
                                        dragIndex === index && "is-dragging",
                                        dragOverIndex === index && dragIndex !== index && "is-dragover"
                                    )}
                                >
                                    <td className="mc-drag-cell">
                                        <span
                                            className={mcClasses("mc-drag-handle")}
                                            title={t("dragtoreorder")}
                                            aria-hidden="true"
                                        >
                                            <i className="bi bi-grip-vertical" />
                                        </span>
                                    </td>
                                    <td>
                                        <div className="fw-semibold">{category.name}</div>
                                        {category.description && (
                                            <div className={mcClasses("mc-cell-muted small")}>{category.description}</div>
                                        )}
                                    </td>
                                    <td><span className={mcClasses("mc-cell-muted mc-mono")}>{category.slug}</span></td>
                                    <td className="text-center">{category.productcount}</td>
                                    <td className="text-center">
                                        <McBadge variant={category.visible ? "success" : "neutral"} tone="soft" dot>
                                            {category.visible ? t("visible") : t("hidden")}
                                        </McBadge>
                                    </td>
                                    <td className="text-end">
                                        <div className={mcClasses("mc-table-design__actions justify-content-end")}>
                                            <McTableActionMenu
                                                label={`${t("actions")}: ${category.name}`}
                                                items={[
                                                    {
                                                        key: "edit",
                                                        label: t("edit"),
                                                        icon: "bi bi-pencil",
                                                        onClick: () => openEdit(category),
                                                    },
                                                    {
                                                        key: "delete",
                                                        label: t("delete"),
                                                        icon: "bi bi-trash",
                                                        danger: true,
                                                        onClick: () => void removeCategory(category),
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
            )}
        </section>
    );
}
