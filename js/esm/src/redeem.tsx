// This file is part of Moodle and is licensed under the
// GNU General Public License, version 3 or later.
//
// You may redistribute and modify it under the terms of the GPL.
// See the plugin root LICENSE file for complete terms.

/**
 * React buyer enrollment key redemption for Modern Commerce.
 *
 * @module     local_moderncommerce/redeem
 * @copyright  2025 Adebare Showemimo | adebareshowemimo@gmail.com | support@agunfoninteractivity.com | www.agunfoninteractivity.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {useState} from "react";
import {mcClasses, useModernCommerceClassSync} from "./design_system";
import {McButton} from "./button";
import ModernLearnerLayout, {type LearnerLayoutContext} from "./learner_layout";

declare const M: {
    cfg: {
        sesskey: string;
        wwwroot: string;
    };
};

type Labels = Record<string, string>;

type Course = {
    id: number;
    fullname: string;
    alreadyenrolled: boolean;
    url: string;
};

type ValidateResponse = {
    valid: boolean;
    message: string;
    courses: Course[];
};

type RedeemResponse = {
    success: boolean;
    message: string;
    courses: Course[];
};

type RedeemProps = {
    validateMethodName: string;
    redeemMethodName: string;
    orderId: number;
    catalogUrl: string;
    coursesUrl: string;
    labels: Labels;
    layout?: LearnerLayoutContext;
    activeNav?: "redeem" | "bundlekeys";
};

const callMoodleService = async <T, >(methodName: string, args: unknown): Promise<T> => {
    const query = new URLSearchParams({
        sesskey: M.cfg.sesskey,
        info: methodName,
    });
    const url = `${M.cfg.wwwroot}/lib/ajax/service.php?${query.toString()}`;
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

export default function Redeem({
    validateMethodName,
    redeemMethodName,
    orderId,
    catalogUrl,
    coursesUrl,
    labels,
    layout,
    activeNav = "redeem",
}: RedeemProps) {
    useModernCommerceClassSync();
    const [keycode, setKeycode] = useState("");
    const [busy, setBusy] = useState<"idle" | "validating" | "redeeming">("idle");
    const [error, setError] = useState("");
    const [preview, setPreview] = useState<Course[] | null>(null);
    const [result, setResult] = useState<Course[] | null>(null);

    const normalised = keycode.trim().toUpperCase();

    const validate = async() => {
        if (normalised === "") {
            return;
        }
        setBusy("validating");
        setError("");
        setPreview(null);

        try {
            const response = await callMoodleService<ValidateResponse>(validateMethodName, {keycode: normalised});
            if (!response.valid) {
                setError(response.message);
                return;
            }
            setPreview(response.courses);
        } catch (caught) {
            setError(caught instanceof Error ? caught.message : String(caught));
        } finally {
            setBusy("idle");
        }
    };

    const redeem = async() => {
        if (normalised === "") {
            return;
        }
        setBusy("redeeming");
        setError("");

        try {
            const response = await callMoodleService<RedeemResponse>(redeemMethodName, {
                keycode: normalised,
                orderid: orderId,
            });
            if (!response.success) {
                setError(response.message);
                return;
            }
            setResult(response.courses);
            setPreview(null);
        } catch (caught) {
            setError(caught instanceof Error ? caught.message : String(caught));
        } finally {
            setBusy("idle");
        }
    };

    const reset = () => {
        setKeycode("");
        setError("");
        setPreview(null);
        setResult(null);
    };

    if (result) {
        return (
            <ModernLearnerLayout
                activeNav={activeNav}
                title={labels.title}
                subtitle={labels.intro}
                labels={labels}
                layout={layout}
            >
                <section className={mcClasses("mc-redeem mc-redeem--success")} aria-label={labels.redeemsuccess}>
                    <div className={mcClasses("mc-card")}>
                        <div className={mcClasses("mc-card-body text-center")}>
                            <span className={mcClasses("mc-redeem__icon text-success")}>
                                <i className="bi bi-check-circle-fill" aria-hidden="true" />
                            </span>
                            <h2 className={mcClasses("mc-card-title mt-2")}>{labels.redeemsuccess}</h2>
                            <div className={mcClasses("mc-redeem__courses mt-3")}>
                                {result.map((course) => (
                                    <div className={mcClasses("mc-redeem__course")} key={course.id}>
                                        <span className="fw-semibold">{course.fullname}</span>
                                        <a className={mcClasses("mc-button btn-mc-primary")} href={course.url}>{labels.accesscourse}</a>
                                    </div>
                                ))}
                            </div>
                            <div className="d-flex flex-wrap justify-content-center gap-2 mt-4">
                                <button className={mcClasses("mc-button btn-mc-secondary")} onClick={reset} type="button">
                                    {labels.redeemanother}
                                </button>
                                <a className={mcClasses("mc-button btn-mc-secondary")} href={coursesUrl}>{labels.mycourses}</a>
                                <a className={mcClasses("mc-button mc-btn-ghost")} href={catalogUrl}>{labels.browsecatalog}</a>
                            </div>
                        </div>
                    </div>
                </section>
            </ModernLearnerLayout>
        );
    }

    return (
        <ModernLearnerLayout
            activeNav={activeNav}
            title={labels.title}
            subtitle={labels.intro}
            labels={labels}
            layout={layout}
            actions={(
                <a className={mcClasses("mc-button btn-mc-secondary")} href={catalogUrl}>
                    <i className="bi bi-grid" aria-hidden="true" />
                    {labels.browsecatalog}
                </a>
            )}
        >
            <section className={mcClasses("mc-redeem")} aria-label={labels.title}>
                <div className={mcClasses("mc-card")}>
                    <div className={mcClasses("mc-card-body")}>
                        <h2 className={mcClasses("mc-card-title")}>{labels.title}</h2>
                        <p className={mcClasses("mc-cell-muted")}>{labels.intro}</p>

                        {error && (
                            <div className={mcClasses("mc-alert mc-alert--danger")} role="alert">
                                <i className="bi bi-exclamation-triangle mc-alert__icon" aria-hidden="true" />
                                <div className={mcClasses("mc-alert__body")}>{error}</div>
                            </div>
                        )}

                        <div className={mcClasses("mc-redeem__form")}>
                            <label className={mcClasses("mc-filter-label")} htmlFor="mc-redeem-keycode">{labels.keycode}</label>
                            <input
                                autoComplete="off"
                                className={mcClasses("mc-form-control")}
                                id="mc-redeem-keycode"
                                onChange={(event) => {
                                    setKeycode(event.target.value);
                                    setPreview(null);
                                    setError("");
                                }}
                                onKeyDown={(event) => {
                                    if (event.key === "Enter") {
                                        void redeem();
                                    }
                                }}
                                placeholder={labels.enterkeycode}
                                type="text"
                                value={keycode}
                            />
                        </div>

                        {preview && preview.length > 0 && (
                            <div className={mcClasses("mc-redeem__preview mt-3")}>
                                <div className={mcClasses("mc-filter-label")}>{labels.grantsaccess}</div>
                                <ul className={mcClasses("mc-redeem__preview-list")}>
                                    {preview.map((course) => (
                                        <li key={course.id}>
                                            <span>{course.fullname}</span>
                                            {course.alreadyenrolled && (
                                                <span className={mcClasses("mc-badge mc-badge--neutral ms-2")}>
                                                    {labels.alreadyenrolled}
                                                </span>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        <div className="d-flex flex-wrap gap-2 mt-3">
                            <McButton
                                className={mcClasses("btn-mc-primary")}
                                disabled={busy !== "idle" || normalised === ""}
                                loading={busy === "redeeming"}
                                loadingLabel={labels.loading || "Redeeming..."}
                                onClick={redeem}
                                type="button"
                            >
                                {labels.redeemkey}
                            </McButton>
                            <button
                                className={mcClasses("mc-button btn-mc-secondary")}
                                disabled={busy !== "idle" || normalised === ""}
                                onClick={validate}
                                type="button"
                            >
                                {busy === "validating" ? labels.loading : labels.validatekey}
                            </button>
                        </div>

                        <p className={mcClasses("mc-cell-muted small mt-3 mb-0")}>{labels.help}</p>
                    </div>
                </div>
            </section>
        </ModernLearnerLayout>
    );
}
