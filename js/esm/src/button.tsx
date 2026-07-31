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
 * Reusable Modern Commerce button primitive for React screens.
 *
 * @module     local_moderncommerce/button
 * @copyright  2025 Adebare Showemimo | adebareshowemimo@gmail.com | support@agunfoninteractivity.com | www.agunfoninteractivity.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import type {ButtonHTMLAttributes, ReactNode} from "react";
import {mcClasses} from "./design_system";

export type McButtonVariant =
    "primary" | "secondary" | "success" | "danger" | "warning" | "info" | "light" | "dark" | "soft" | "ghost";

export type McButtonSize = "sm" | "lg" | "icon";

type McButtonState = "hover" | "active" | "disabled" | "loading";

export type McButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
    variant?: McButtonVariant;
    size?: McButtonSize;
    loading?: boolean;
    loadingLabel?: ReactNode;
    buttonState?: McButtonState;
    block?: boolean;
};

/**
 * Button wrapper that keeps the existing mc-button styling API while
 * standardising the loading state used by save/submit actions.
 *
 * @param props Button props.
 * @returns A button element with Modern Commerce loading semantics.
 */
export function McButton({
    variant,
    size,
    loading = false,
    loadingLabel,
    buttonState,
    block = false,
    className,
    children,
    disabled,
    type = "button",
    "aria-busy": ariaBusy,
    ...buttonProps
}: McButtonProps) {
    const busy = loading || ariaBusy === true || ariaBusy === "true";

    return (
        <button
            {...buttonProps}
            aria-busy={busy ? "true" : ariaBusy}
            className={mcClasses("mc-button", block && "mc-button--block", className)}
            data-mc-button={variant}
            data-mc-button-size={size}
            data-mc-button-state={busy ? "loading" : buttonState}
            disabled={disabled || loading}
            type={type}
        >
            {loading && loadingLabel !== undefined ? loadingLabel : children}
        </button>
    );
}
