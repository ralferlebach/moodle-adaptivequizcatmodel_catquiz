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
 * User stories of the activity driven by the CATquiz CAT model.
 *
 * These need all three plugins installed: mod_adaptivequiz, adaptivequizcatmodel_catquiz and
 * local_catquiz. They live here, in the adapter, because it is the only one of the three that may
 * know both the host and CATquiz; the host keeps no reference to a real CAT model (host issue #10).
 * Run them from the host's Playwright setup, which provides the helpers and the site seed. They walk what the contract between them is supposed to produce - a CAT model
 * takes over question selection, the built-in report disappears, the CAT model's own feedback and
 * report take its place.
 *
 * @package    adaptivequizcatmodel_catquiz
 * @copyright  2026 onwards Ralf Erlebach
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const {test, expect} = require('@playwright/test');
const {login, logout, milestone, open_activity} = require('../../../../tests/playwright/stories/helpers');

const COURSE = process.env.STORY_COURSE || 'PWC1';
const ACTIVITY = process.env.STORY_CATQUIZ_ACTIVITY || 'CATquiz adaptive quiz';

test.describe('mod_adaptivequiz driven by CATquiz', () => {
    test.afterEach(async ({page}) => {
        await logout(page);
    });

    test('A teacher chooses the CATquiz model and the built-in settings give way', async ({page}, testInfo) => {
        await login(page, 'pwteacher', process.env.STORY_PASSWORD || 'Story123!');

        await page.goto('/course/modedit.php?add=adaptivequiz&course=' +
            encodeURIComponent(process.env.STORY_COURSEID || '2') + '&section=1');
        await milestone(page, testInfo, '01-activity-form-default');

        const chooser = page.locator('#id_catmodel');
        await expect(chooser).toBeVisible();
        await chooser.selectOption({label: /catquiz/i});
        await page.waitForLoadState('networkidle');
        await milestone(page, testInfo, '02-catmodel-chosen');

        // The settings of the built-in algorithm do not apply to an activity the CAT model drives.
        await expect(page.locator('#id_startinglevel')).toBeHidden();
        await milestone(page, testInfo, '03-builtin-settings-gone');
    });

    test('A student takes a CATquiz attempt and sees the feedback of the CAT model', async ({page}, testInfo) => {
        await login(page, 'pwstudent', process.env.STORY_PASSWORD || 'Story123!');
        await open_activity(page, COURSE, ACTIVITY);
        await milestone(page, testInfo, '01-activity-page');

        await page.getByRole('button', {name: /start attempt/i}).click();
        await page.waitForLoadState('networkidle');
        await milestone(page, testInfo, '02-first-question-chosen-by-catquiz');

        for (let step = 0; step < 30; step++) {
            const answer = page.locator('input[type=radio]').first();
            if (!await answer.count()) {
                break;
            }
            await answer.check();
            await page.getByRole('button', {name: /submit answer/i}).click();
            await page.waitForLoadState('networkidle');
        }

        await milestone(page, testInfo, '03-catquiz-feedback');
    });

    test('A teacher reaches the CATquiz report through the attempts number', async ({page}, testInfo) => {
        await login(page, 'pwteacher', process.env.STORY_PASSWORD || 'Story123!');
        await open_activity(page, COURSE, ACTIVITY);
        await milestone(page, testInfo, '01-activity-page-as-teacher');

        // With a CAT model the built-in report gives way to the number of attempts, linked to the
        // report the CAT model offers. Without that link there is no way to reach an overview.
        const link = page.getByRole('link', {name: /attempts:/i});
        await expect(link).toBeVisible();
        await link.click();
        await page.waitForLoadState('networkidle');
        await milestone(page, testInfo, '02-catquiz-report');
    });
});
