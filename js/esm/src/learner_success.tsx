// This file is part of Moodle - http://www.moodle.org/
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

/**
 * Checkout success / thank-you screen, rendered inside the learner sidebar layout.
 *
 * The order confirmation content (purchased items + order summary) is built
 * server-side and passed through as trusted HTML so this view only supplies the
 * shared ModernLearnerLayout chrome (sidebar + hero band) around it.
 *
 * @module     local_moderncommerce/learner_success
 * @copyright  2025 Adebare Showemimo | adebareshowemimo@gmail.com | support@agunfoninteractivity.com | www.agunfoninteractivity.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import type {Labels} from "./learner_common";
import {useModernCommerceClassSync} from "./design_system";
import ModernLearnerLayout, {type LearnerLayoutContext} from "./learner_layout";

type LearnerSuccessProps = {
    title: string;
    eyebrow?: string;
    subtitle?: string;
    contentHtml: string;
    labels: Labels;
    layout?: LearnerLayoutContext;
};

export default function LearnerSuccess({
    title,
    eyebrow,
    subtitle,
    contentHtml,
    labels,
    layout,
}: LearnerSuccessProps) {
    useModernCommerceClassSync();

    return (
        <ModernLearnerLayout
            activeNav="orders"
            title={title}
            eyebrow={eyebrow}
            subtitle={subtitle}
            labels={labels}
            layout={layout}
        >
            <div className="mc-success-content" dangerouslySetInnerHTML={{__html: contentHtml}} />
        </ModernLearnerLayout>
    );
}
