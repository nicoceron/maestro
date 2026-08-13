import { defineConfig, devices } from '@playwright/test';

const externalServer = process.env.BROWSER_EXTERNAL_SERVER === 'true';
const baseURL = process.env.BROWSER_BASE_URL ?? 'http://127.0.0.1:18120';

export default defineConfig({
    testDir: './tests/Browser',
    fullyParallel: false,
    forbidOnly: Boolean(process.env.CI),
    retries: process.env.CI ? 1 : 0,
    workers: 1,
    reporter: process.env.CI
        ? [['line'], ['html', { open: 'never' }]]
        : [['list'], ['html', { open: 'never' }]],
    use: {
        baseURL,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
        locale: 'en-US',
        timezoneId: 'America/Bogota',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
    webServer: externalServer
        ? undefined
        : {
              command: 'php artisan serve --host=127.0.0.1 --port=18120',
              url: `${baseURL}/manage/login`,
              reuseExistingServer: !process.env.CI,
              timeout: 120_000,
              stdout: 'pipe',
              stderr: 'pipe',
          },
});
