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
 * Shared Modern Commerce admin table primitives.
 *
 * @module     local_moderncommerce/table_components
 * @copyright  2025 Adebare Showemimo | adebareshowemimo@gmail.com | support@agunfoninteractivity.com | www.agunfoninteractivity.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import type {CSSProperties, ReactNode} from "react";
import {useCallback, useEffect, useLayoutEffect, useRef, useState} from "react";
import {createPortal} from "react-dom";
import {mcClasses} from "./design_system";

type McTableCardProps = {
    title?: ReactNode;
    actions?: ReactNode;
    toolbar?: ReactNode;
    children: ReactNode;
    footer?: ReactNode;
    alert?: ReactNode;
    className?: string;
};

type McTableFrameProps = {
    children: ReactNode;
    className?: string;
    wrapperClassName?: string;
};

type McTableFooterProps = {
    summary?: ReactNode;
    pagination?: ReactNode;
    children?: ReactNode;
    className?: string;
};

type McTablePaginationProps = {
    previousLabel: ReactNode;
    nextLabel: ReactNode;
    pageLabel?: ReactNode;
    page?: number;
    totalPages?: number;
    previousDisabled?: boolean;
    nextDisabled?: boolean;
    onPrevious: () => void;
    onNext: () => void;
};

export type McTableActionMenuItem = {
    key: string | number;
    label: ReactNode;
    icon?: string;
    href?: string;
    disabled?: boolean;
    danger?: boolean;
    current?: boolean;
    title?: string;
    onClick?: () => void;
};

type McTableActionMenuProps = {
    label: string;
    menuLabel?: string;
    items: McTableActionMenuItem[];
    disabled?: boolean;
    className?: string;
};

type MenuPosition = {
    top: number;
    left: number;
};

/**
 * Standard table card shell: title/actions in the header, filters and the
 * framed table in the body, and summary/pagination in the footer.
 *
 * @param props Component props.
 * @returns Table card shell.
 */
export function McTableCard({title, actions, toolbar, children, footer, alert, className}: McTableCardProps) {
    const hasHeader = title !== undefined || actions !== undefined;

    return (
        <div className={mcClasses("mc-card mc-card--table-design", className)}>
            {hasHeader && (
                <div className={mcClasses("mc-card-header mc-table-design-controls mc-table-design-controls--stacked")}>
                    <div className={mcClasses("mc-table-design-header-row")}>
                        {title !== undefined && <div className={mcClasses("mc-table-design-title")}>{title}</div>}
                        {actions !== undefined && <div className={mcClasses("mc-table-design-actions")}>{actions}</div>}
                    </div>
                </div>
            )}
            <div className={mcClasses("mc-table-design-body")}>
                {toolbar !== undefined && (
                    <div className={mcClasses("mc-table-design-controls__filters")}>
                        {toolbar}
                    </div>
                )}
                {alert}
                <McTableFrame>{children}</McTableFrame>
            </div>
            {footer}
        </div>
    );
}

/**
 * Standard framed table body.
 *
 * @param props Component props.
 * @returns Table frame.
 */
export function McTableFrame({children, className, wrapperClassName}: McTableFrameProps) {
    return (
        <div className={mcClasses("mc-table-frame", className)}>
            <div className={mcClasses("mc-table-wrapper", wrapperClassName)}>
                {children}
            </div>
        </div>
    );
}

/**
 * Standard table footer with summary on the left and pagination on the right.
 *
 * @param props Component props.
 * @returns Table footer.
 */
export function McTableFooter({summary, pagination, children, className}: McTableFooterProps) {
    return (
        <div className={mcClasses("mc-card-footer mc-table-design-footer", className)}>
            {children ?? (
                <>
                    <div className={mcClasses("mc-product-admin__summary mc-table-design-footer-summary")}>
                        {summary}
                    </div>
                    {pagination}
                </>
            )}
        </div>
    );
}

/**
 * Simple previous/next pagination control for table footers.
 *
 * @param props Component props.
 * @returns Pagination control.
 */
export function McTablePagination({
    previousLabel,
    nextLabel,
    pageLabel,
    page,
    totalPages,
    previousDisabled = false,
    nextDisabled = false,
    onPrevious,
    onNext,
}: McTablePaginationProps) {
    return (
        <div className={mcClasses("mc-product-pagination")}>
            <button
                className={mcClasses("mc-button mc-btn-soft")}
                disabled={previousDisabled}
                onClick={onPrevious}
                type="button"
            >
                {previousLabel}
            </button>
            {pageLabel !== undefined && (
                <span className={mcClasses("mc-cell-muted small")}>
                    {pageLabel}
                    {page !== undefined && totalPages !== undefined && <> {page} / {totalPages}</>}
                </span>
            )}
            <button
                className={mcClasses("mc-button mc-btn-soft")}
                disabled={nextDisabled}
                onClick={onNext}
                type="button"
            >
                {nextLabel}
            </button>
        </div>
    );
}

/**
 * Compact row action menu. The menu is portalled so it is not clipped by table
 * overflow containers.
 *
 * @param props Component props.
 * @returns Action menu trigger and menu.
 */
export function McTableActionMenu({label, menuLabel, items, disabled = false, className}: McTableActionMenuProps) {
    const [open, setOpen] = useState(false);
    const [position, setPosition] = useState<MenuPosition>({top: 0, left: 0});
    const triggerRef = useRef<HTMLButtonElement>(null);
    const menuRef = useRef<HTMLDivElement>(null);
    const menuId = useRef(`mc-table-action-menu-${Math.random().toString(36).slice(2)}`);

    const updatePosition = useCallback(() => {
        const trigger = triggerRef.current;
        if (!trigger) {
            return;
        }

        const rect = trigger.getBoundingClientRect();
        const menu = menuRef.current;
        const gap = 6;
        const viewportPadding = 8;
        const menuWidth = menu?.offsetWidth ?? 176;
        const menuHeight = menu?.offsetHeight ?? 120;
        const opensUp = rect.bottom + gap + menuHeight > window.innerHeight && rect.top > menuHeight;
        const top = opensUp ? rect.top - gap - menuHeight : rect.bottom + gap;
        const left = Math.max(viewportPadding, Math.min(rect.right - menuWidth, window.innerWidth - menuWidth - viewportPadding));

        setPosition({top, left});
    }, []);

    useLayoutEffect(() => {
        if (open) {
            updatePosition();
        }
    }, [open, updatePosition]);

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        const handlePointer = (event: MouseEvent) => {
            const target = event.target as Node;
            if (triggerRef.current?.contains(target) || menuRef.current?.contains(target)) {
                return;
            }
            setOpen(false);
        };
        const handleKey = (event: KeyboardEvent) => {
            if (event.key === "Escape") {
                setOpen(false);
                triggerRef.current?.focus();
            }
        };

        document.addEventListener("mousedown", handlePointer);
        document.addEventListener("keydown", handleKey);
        window.addEventListener("resize", updatePosition);
        window.addEventListener("scroll", updatePosition, true);

        return () => {
            document.removeEventListener("mousedown", handlePointer);
            document.removeEventListener("keydown", handleKey);
            window.removeEventListener("resize", updatePosition);
            window.removeEventListener("scroll", updatePosition, true);
        };
    }, [open, updatePosition]);

    const menuStyle: CSSProperties = {
        position: "fixed",
        top: `${position.top}px`,
        left: `${position.left}px`,
        right: "auto",
    };

    const menu = open && createPortal(
        <div
            ref={menuRef}
            className={mcClasses("mc-table-design__action-menu mc-table-design__action-menu--fixed")}
            id={menuId.current}
            role="menu"
            aria-label={menuLabel ?? label}
            style={menuStyle}
        >
            {items.map((item) => {
                const itemClass = mcClasses(
                    "mc-table-design__action-menu-item",
                    item.danger && "mc-table-design__action-menu-item--danger",
                    item.disabled && "is-disabled",
                );
                const content = (
                    <>
                        {item.icon && <i className={item.icon} aria-hidden="true" />}
                        <span>{item.label}</span>
                    </>
                );

                if (item.href && !item.disabled) {
                    return (
                        <a
                            aria-current={item.current ? "true" : undefined}
                            className={itemClass}
                            href={item.href}
                            key={item.key}
                            role="menuitem"
                            title={item.title}
                        >
                            {content}
                        </a>
                    );
                }

                return (
                    <button
                        aria-current={item.current ? "true" : undefined}
                        className={itemClass}
                        disabled={item.disabled}
                        key={item.key}
                        onClick={() => {
                            item.onClick?.();
                            setOpen(false);
                        }}
                        role="menuitem"
                        title={item.title}
                        type="button"
                    >
                        {content}
                    </button>
                );
            })}
        </div>,
        document.body
    );

    return (
        <div className={mcClasses("mc-table-design__action-menu-wrap", className)}>
            <button
                ref={triggerRef}
                aria-controls={open ? menuId.current : undefined}
                aria-expanded={open}
                aria-haspopup="menu"
                aria-label={label}
                className={mcClasses("mc-table-design__action mc-table-design__action--menu")}
                disabled={disabled || items.length === 0}
                onClick={() => setOpen((current) => !current)}
                title={label}
                type="button"
            >
                <i className="bi bi-three-dots" aria-hidden="true" />
            </button>
            {menu}
        </div>
    );
}
