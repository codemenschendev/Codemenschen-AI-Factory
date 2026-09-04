# Ad accounts: Google Ads and Meta

Campaigns run on Codemenschen's own ad accounts. The customer pays; the monthly budget they bought
is set as the platform's own spend cap. The code for both platforms is in
`apps/api/app/Domain/Ads/` and is complete. What is missing on the server is credentials.

Check the state at any time, from the platforms themselves, without creating or spending anything:

    docker exec infra-api-1 php artisan factory:ads-check

The admin overview (`/de/admin`) shows the same thing without the API call: connected or not, and
which env keys are still empty.

Secrets go into `/var/www/ai-factory/apps/api/.env` on the server, written by a person, never
pasted into a chat. Then recreate the containers, not restart them: `env_file` is read when a
container is created, and `restart` keeps the old environment.

    cd /var/www/ai-factory && docker compose -f infra/docker-compose.prod.yml up -d --force-recreate api horizon

To write a secret without it echoing or landing in shell history:

    ssh -t -p 7172 root@65.108.206.249 'f=/var/www/ai-factory/apps/api/.env; read -r -s -p "KEY: " v; echo; sed -i "s|^KEY=.*|KEY=$v|" $f'

## Google Ads

Two accounts are involved and they are not the same thing.

**Google Cloud** holds the OAuth client. **Google Ads** (a Manager account, "MCC") holds the
developer token and the ad account. The API refuses without both.

1. Google Cloud console: one project, name it `codemenschen-ads`. APIs & Services, enable
   **Google Ads API**.
2. OAuth consent screen: External, app name Appwerk, add your own Google account as a test user.
   Scope `https://www.googleapis.com/auth/adwords`. It can stay in Testing; only our own account
   ever signs in.
3. Credentials, create **OAuth client ID**, type **Desktop app**. Note the client id and secret.
4. Refresh token: on the Mac, with the client id and secret in the environment,

        GOOGLE_ADS_CLIENT_ID=... GOOGLE_ADS_CLIENT_SECRET=... python3 apps/api/tools/google-ads-oauth.py

   It opens the consent page, catches the redirect on localhost, and prints the refresh token to
   your terminal and nowhere else.
5. Google Ads, Manager account: Tools, API Center, apply for a **developer token**. A new token
   has *test* access, which only works against test ad accounts. Apply for **Basic access** the
   same day: Google reviews by hand and it takes days to weeks. Nothing in our code changes when
   it lands; the same token simply starts working against the real account.
6. Note the 10-digit **customer id** of the ad account that will run campaigns (no dashes), and
   the customer id of the Manager account as `login_customer_id`.

Env keys:

    GOOGLE_ADS_DEVELOPER_TOKEN
    GOOGLE_ADS_CUSTOMER_ID            10 digits, the ad account
    GOOGLE_ADS_LOGIN_CUSTOMER_ID      10 digits, the Manager account (optional if the same)
    GOOGLE_ADS_CLIENT_ID
    GOOGLE_ADS_CLIENT_SECRET
    GOOGLE_ADS_REFRESH_TOKEN

## Meta (Facebook and Instagram)

Everything lives in **Meta Business Suite** for the Codemenschen business.

1. business.facebook.com, Settings, Business assets: an **ad account** (note its id; the env
   value is `act_` plus the number) and the **Facebook Page** ads are published from (note the
   page id). The Instagram account is linked to that page.
2. developers.facebook.com: one app, type Business, add the **Marketing API** product.
3. Business settings, Users, **System users**: create one, role Admin, assign the ad account
   (manage) and the page (manage). Generate a token with `ads_management`, `ads_read`,
   `pages_read_engagement`, `pages_manage_ads`, `business_management`. Choose *never expires*.
4. While the app is in Development mode the token works only for people with a role on the app,
   which is fine: our own system user publishes, nobody else. App Review is only needed if we
   ever act on other businesses' accounts, which by design we do not.

Env keys:

    META_ADS_TOKEN
    META_ADS_ACCOUNT_ID               act_1234567890
    META_ADS_PAGE_ID

## What happens after

`publish` creates every object PAUSED. Nothing spends until a person presses activate in the
portal. `factory:ads-check` reads `account_status` from Meta; anything but 1 (active) means the
account cannot spend and the check says so.

## Not covered here

Store publishing (App Store Connect, Play Developer API) is a separate topic: each customer app
ships under the customer's own developer account, per PLAN.md.
