// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Shared learner statistic tiles for Modern Commerce.
 *
 * @module     local_moderncommerce/learner_stat_tiles
 * @copyright  2025 Adebare Showemimo | adebareshowemimo@gmail.com | support@agunfoninteractivity.com | www.agunfoninteractivity.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import type {ReactNode} from "react";
import {clampProgress, formatCount} from "./learner_common";
import {mcClasses} from "./design_system";

type LearnerStatTileVariant = "primary" | "success" | "warning" | "danger" | "info" | "muted" | "neutral";

type LearnerStatStripProps = {
    children: ReactNode;
    className?: string;
};

type LearnerStatTileProps = {
    label: string;
    value: string | number;
    icon: string;
    variant?: LearnerStatTileVariant;
    progress?: number;
    href?: string;
    featured?: boolean;
};

const formatValue = (value: string | number): string => {
    return typeof value === "number" ? formatCount(value) : value;
};

export function LearnerStatStrip({
    children,
    className,
}: LearnerStatStripProps) {
    return (
        <div className={mcClasses("mc-stat-strip mc-learner-stat-strip", className)} role="list">
            {children}
        </div>
    );
}

export function LearnerStatTile({
    label,
    value,
    icon,
    variant = "primary",
    progress,
    href,
    featured = false,
}: LearnerStatTileProps) {
    const progressValue = typeof progress === "number" ? clampProgress(progress) : null;
    const className = mcClasses(
        "mc-stat-tile",
        "mc-learner-stat-tile",
        `mc-stat-tile--${variant}`,
        href && "mc-learner-stat-tile--link",
        featured && "mc-learner-stat-tile--featured"
    );
    const content = (
        <>
            <i className={`bi ${icon} mc-stat-tile__icon`} aria-hidden="true" />
            <div className={mcClasses("mc-stat-tile__body")}>
                <span className={mcClasses("mc-stat-tile__label")}>{label}</span>
                <strong className={mcClasses("mc-stat-tile__value")}>{formatValue(value)}</strong>
                {progressValue !== null && (
                    <span
                        className={mcClasses("mc-learner-stat-tile__progress")}
                        aria-hidden="true"
                    >
                        <span style={{width: `${progressValue}%`}} />
                    </span>
                )}
            </div>
            <i className={`bi ${icon} mc-stat-tile__watermark`} aria-hidden="true" />
        </>
    );

    if (href) {
        return (
            <a className={className} href={href} role="listitem">
                {content}
            </a>
        );
    }

    return (
        <article className={className} role="listitem">
            {content}
        </article>
    );
}
