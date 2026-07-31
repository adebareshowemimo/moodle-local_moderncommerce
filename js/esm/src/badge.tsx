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
 * Shared Modern Commerce badge primitives.
 *
 * @module     local_moderncommerce/badge
 * @copyright  2025 Adebare Showemimo | adebareshowemimo@gmail.com | support@agunfoninteractivity.com | www.agunfoninteractivity.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import type {ReactNode} from "react";
import {mcClasses} from "./design_system";

export type McBadgeVariant = "primary" | "secondary" | "accent" | "success" | "warning" | "danger" | "info" | "neutral";
export type McBadgeTone = "subtle" | "soft" | "medium" | "strong";
export type McBadgeSize = "sm" | "md" | "lg";

type McBadgeProps = {
    children: ReactNode;
    variant?: McBadgeVariant;
    tone?: McBadgeTone;
    size?: McBadgeSize;
    icon?: string;
    dot?: boolean;
    className?: string;
    title?: string;
};

type McBadgeGroupProps = {
    children: ReactNode;
    inline?: boolean;
    stacked?: boolean;
    className?: string;
};

/**
 * Brand-aware badge for status labels, metadata, and small feature markers.
 *
 * @param props Component props.
 * @returns Badge element.
 */
export function McBadge({
    children,
    variant = "neutral",
    tone = "soft",
    size = "md",
    icon,
    dot = false,
    className,
    title,
}: McBadgeProps) {
    return (
        <span
            className={mcClasses(
                "mc-badge",
                `mc-badge--${variant}`,
                `mc-badge--tone-${tone}`,
                size !== "md" && `mc-badge--${size}`,
                className,
            )}
            title={title}
        >
            {dot && <span className={mcClasses("mc-badge__dot")} aria-hidden="true" />}
            {icon && <i className={mcClasses("bi", icon, "mc-badge__icon")} aria-hidden="true" />}
            <span className={mcClasses("mc-badge__label")}>{children}</span>
        </span>
    );
}

/**
 * Flexible wrapper for adjacent badges.
 *
 * @param props Component props.
 * @returns Badge group.
 */
export function McBadgeGroup({children, inline = false, stacked = false, className}: McBadgeGroupProps) {
    return (
        <span
            className={mcClasses(
                "mc-badge-group",
                inline && "mc-badge-group--inline",
                stacked && "mc-badge-group--stacked",
                className,
            )}
        >
            {children}
        </span>
    );
}
