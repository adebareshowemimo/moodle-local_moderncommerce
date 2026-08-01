---
title: Configure Course Certificates
slug: configure_certificate
plugin: mod_coursecertificate
---

# Configure Course Certificates (mod_coursecertificate)

## Overview
This guide helps Moodle admins add and configure Course Certificates for a specific course using mod/coursecertificate, set activity completion, define issuance requirements, and create custom certificate templates.

## Prerequisites
- Role: Course manager or site administrator with `moodle/course:update`.
- Plugin: `mod_coursecertificate` installed and enabled.
- Completion tracking enabled at site and course levels.
- The course ID you want to configure.

## Navigation Path
- Course page → Turn editing on → Add an activity or resource → Course certificate
- Course page → More → Course completion
- Site administration → Plugins → Activity modules → Course certificate (module settings)

## 1. Add Course Certificate to the Course
1. Open the course page.
2. Click "Turn editing on".
3. Click "Add an activity or resource" in the section where you want the certificate.
4. Select "Course certificate" (mod/coursecertificate) and click "Add".
5. Fill out:
   - Name: e.g., "Completion Certificate".
   - Availability/Restrict access: leave default for now.
   - Activity completion: set to "Show activity as complete when conditions are met".
6. Save and return to course.

## 2. Set Up Activity Completion
1. In the course: click More → Course completion.
2. Enable completion tracking and set criteria, e.g.:
   - Require passing grade in final quiz.
   - Require viewing all lessons.
   - Require minimum attendance (if relevant module is present).
3. Save changes.

## 3. Define Requirements to Issue Certificate
1. Edit the Course certificate activity.
2. Configure "Activity completion" for the certificate to depend on the course completion criteria.
3. Use "Restrict access" to gate the certificate until completion conditions are met, e.g.:
   - Add restriction → Activity completion → select required activities completed.
   - Add restriction → Grade → final quiz grade >= passing.
4. Save and test with a student account.

## 4. Create a Custom Certificate Template
1. In the Course certificate settings, select or create a template.
2. Add fields (learner name, course name, completion date) and branding elements (logo, signature).
3. Position elements and fine-tune fonts and sizes.
4. Preview the certificate.
5. Save template and assign it to the Course certificate activity.

## Tips & Pitfalls
- Ensure site-level completion tracking is on; otherwise course completion rules won’t apply.
- Keep Restrict access simple; too many conditions can be confusing.
- Test with a non-admin learner to validate issuance.

## Checklist
<ul style="list-style: none; padding-left: 0;">
  <li><label><input type="checkbox"> mod/coursecertificate installed</label></li>
  <li><label><input type="checkbox"> Course certificate added to course</label></li>
  <li><label><input type="checkbox"> Course completion criteria configured</label></li>
  <li><label><input type="checkbox"> Restrict access rules applied</label></li>
  <li><label><input type="checkbox"> Custom template created and assigned</label></li>
  <li><label><input type="checkbox"> Tested with a student account</label></li>
</ul>

## Moodle Docs
- https://docs.moodle.org/en/Certificates
- https://docs.moodle.org/en/Activity_completion
- https://docs.moodle.org/en/Course_completion

## Quick Links
- Open this course: {{courseurl}}
- Course completion settings: {{completionurl}}
- Add activity (Course certificate): Open course and click "Add an activity or resource".
