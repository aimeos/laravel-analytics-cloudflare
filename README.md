# laravel-analytics-cloudflare

Cloudflare driver for Laravel Analytics bridge.

Cloudflare Web Analytics requires two credentials to query data via the Analytics API:

- **siteTag** → A unique identifier for your website.
- **API Token** → An access token that authorizes your app to query the GraphQL API.

## 1. Find Your `siteTag`

The `siteTag` is generated when you enable **Web Analytics** for a domain.

1. Log in to [Cloudflare Dashboard](https://dash.cloudflare.com/).
2. Select your **website** from the list.
3. In the sidebar, go to **Analytics → Web Analytics**.
4. If you haven’t yet enabled Web Analytics, click **Enable Web Analytics** and follow the setup.
5. Cloudflare will show you a **JavaScript snippet** to embed in your site.
   Example:
   ```html
   <script defer src="https://static.cloudflareinsights.com/beacon.min.js"
           data-cf-beacon='{"token": "abcd1234efgh5678ijkl9012mnop3456", "siteTag": "xyz987654321"}'></script>
    ```
6. Copy the `siteTag` value from inside the `data-cf-beacon` attribute.

> **Note**: The `siteTag` is unique per domain you configure.

---

## 2. Create an API Token

You need a token to access the **Cloudflare GraphQL Analytics API**.

1. Go to your [Cloudflare Dashboard → My Profile → API Tokens](https://dash.cloudflare.com/profile/api-tokens).
2. Click **Create Token**.
3. Choose **Create Custom Token**.
4. Add permissions:
   - **Account → Analytics → Read**
   - (If you only want Web Analytics, this scope is enough.)
5. Select the **account** that contains your website.
6. Save and copy the generated **API Token**.

## 3. Configure in Analytics Bridge

The `./config/analytics-bridge.php` file already contains:

```php
return [
    'default' => env('ANALYTICS_DRIVER'),

    'drivers' => [
        'cloudflare' => [
            'siteTag' => env('CLOUDFLARE_SITETAG'),
            'token' => env('CLOUDFLARE_TOKEN'),
        ],
        /* ... */
    ],
    /* ... */
];
```

Add the required key/value pairs to your `.env` file:

```
ANALYTICS_DRIVER="cloudflare"
CLOUDFLARE_SITETAG="..."
CLOUDFLARE_TOKEN="..."
```
