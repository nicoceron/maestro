import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

const owner = {
    email: 'browser.owner@example.test',
    password: 'Browser quality passphrase 2026!',
};

const studioPath = '/manage/studio/browser-studio';

async function signIn(page) {
    await page.goto('/manage/login', { waitUntil: 'domcontentloaded' });
    await page.getByRole('textbox', { name: 'Email address*', exact: true }).fill(owner.email);
    await page.getByRole('textbox', { name: 'Password*', exact: true }).fill(owner.password);
    await Promise.all([
        page.waitForURL(new RegExp(`${studioPath}/?$`), { waitUntil: 'domcontentloaded' }),
        page.getByRole('button', { name: 'Sign in', exact: true }).click(),
    ]);
}

async function expectNoAccessibilityViolations(page, testInfo) {
    await expect
        .poll(() =>
            page.evaluate(
                () =>
                    document
                        .getAnimations()
                        .filter(
                            (animation) =>
                                animation.effect?.getTiming().iterations !== Infinity &&
                                animation.playState === 'running',
                        ).length,
            ),
        )
        .toBe(0);

    const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'])
        .analyze();

    await testInfo.attach('axe-results', {
        body: JSON.stringify(results, null, 2),
        contentType: 'application/json',
    });

    expect(results.violations).toEqual([]);
}

async function expectNoHorizontalOverflow(page) {
    await expect
        .poll(() => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth))
        .toBe(true);
}

test.beforeEach(async ({ page }) => {
    await page.clock.setFixedTime(new Date('2026-08-13T14:00:00Z'));
});

test('owner navigates the real calendar views with keyboard-accessible controls', async ({ page }, testInfo) => {
    await signIn(page);
    await page.goto(`${studioPath}/calendar`, { waitUntil: 'domcontentloaded' });

    await expect(page.getByRole('heading', { name: 'Studio calendar' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Timeline' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Agenda' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Print' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Manage hold' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Roster', exact: true })).toBeVisible();
    await expect(page.locator('[data-calendar-status]')).toContainText('No events in this date range.');

    const timeline = page.getByRole('button', { name: 'Timeline' });
    await timeline.click();
    await expect(page.getByRole('heading', { name: 'August 2026' })).toBeVisible();
    const agenda = page.getByRole('button', { name: 'Agenda' });
    await agenda.click();
    await expect(page.getByRole('heading', { name: 'August 9 – 15, 2026' })).toBeVisible();

    await page.keyboard.press('Tab');
    await expect(page.locator(':focus-visible')).toBeVisible();
    await expectNoHorizontalOverflow(page);
    await expectNoAccessibilityViolations(page, testInfo);
});

test('teaching and template workflows expose honest empty states and a usable modal', async ({ page }, testInfo) => {
    await signIn(page);

    await page.goto(`${studioPath}/teaching`, { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { name: 'Teaching workspace' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'No recent lessons need attention' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'No lesson notes yet' })).toBeVisible();
    await expectNoAccessibilityViolations(page, testInfo);

    await page.goto(`${studioPath}/lesson-note-templates`, { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { name: 'Lesson note templates' })).toBeVisible();
    await expect(page.getByText('No lesson-note templates')).toBeVisible();
    await page.getByRole('button', { name: 'New template' }).click();
    await expect(page.getByRole('heading', { name: 'Create a lesson-note template' })).toBeVisible();
    await expect(page.getByRole('textbox', { name: 'Name*', exact: true })).toBeFocused();
    await expectNoAccessibilityViolations(page, testInfo);
});

test('mobile scheduling routes stay within the viewport', async ({ page }, testInfo) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await signIn(page);

    for (const route of ['calendar', 'teaching', 'lesson-note-templates']) {
        await page.goto(`${studioPath}/${route}`, { waitUntil: 'domcontentloaded' });
        await expect(page.locator('main')).toBeVisible();
        await expectNoHorizontalOverflow(page);
    }

    await expectNoAccessibilityViolations(page, testInfo);
});

test('calendar visual baseline remains stable on the CI browser image', async ({ page }) => {
    test.skip(process.platform !== 'linux', 'The visual baseline is generated and verified on Linux CI.');

    await signIn(page);
    await page.goto(`${studioPath}/calendar`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[data-calendar-status]')).toContainText('No events in this date range.');
    await page.addStyleTag({ content: '.fi-topbar { display: none !important; }' });
    await expect(page.locator('[data-calendar-root]')).toHaveScreenshot('calendar-desktop.png', {
        animations: 'disabled',
        caret: 'hide',
    });
});
