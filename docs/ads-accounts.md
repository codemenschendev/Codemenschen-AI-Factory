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

Everything runs under **codemenschenapp@gmail.com** (decision 2026-09-15): the Cloud project
`cm-ops` and the ad account the factory publishes to.

**Sign-in: a service account** (decision 2026-09-15), not a person's refresh token. A refresh
token is a person's identity: it dies when that person changes their password or withdraws
consent, and after 7 days while the consent screen is in Testing. Every repair then needs their
machine and their second factor. The service account
`appwerk-ads@cm-ops-507408.iam.gserviceaccount.com` never expires and needs no browser. Google
Ads accepts it as a user directly; domain-wide delegation and a Workspace domain are not needed.

1. Key file on the server, outside the checkout, `/var/lib/ai-factory/secrets/google-ads-sa.json`
   (chmod 600). Compose mounts the directory read-only into api and horizon and sets
   `GOOGLE_ADS_SERVICE_ACCOUNT_JSON`. The OAuth keys below are then ignored.
2. Google Ads, **on the ad account itself**, Admin, Access and security, Users, +: add the
   service account e-mail with **Standard** access. It counts at once, there is no invitation
   mail to accept.
3. `GOOGLE_ADS_CUSTOMER_ID` = that account's ten digits, `GOOGLE_ADS_LOGIN_CUSTOMER_ID` empty.

**Grant it on the ad account, never on a Manager** (decision 2026-09-22). Standard access on a
Manager reaches every account under it, so a mistake could spend a client's money. Granted on one
ad account, that account is the most the factory can ever touch.

**Appwerk advertises for itself first** (decision 2026-09-22). One ad account of our own, with
its own payment method and its own Account Spending Limit in Google Ads. A customer who wants ads
later adds the same service account to their own account, which also keeps their spend on their
own invoice.

`authenticationError.NOT_ADS_USER` means step 2 is missing on the account named in
`GOOGLE_ADS_CUSTOMER_ID`.

Two accounts are involved and they are not the same thing.

**Google Cloud** holds the OAuth client and, since 2026-09-09, the API access level. **Google
Ads** holds the ad account. There is no developer token any more: Google retired them and moved
access onto the Cloud project that owns the OAuth client.

1. Google Cloud console: one project, name it `codemenschen-ads`. APIs & Services, enable
   **Google Ads API**.
2. OAuth consent screen: External, app name Appwerk, add your own Google account as a test user.
   Scope `https://www.googleapis.com/auth/adwords`. Then press **Publish app** (In production).
   In Testing a refresh token dies after 7 days (`invalid_grant: Token has been expired or
   revoked`); that killed the token from 2026-09-04. The adwords scope is not sensitive, so
   publishing needs no review; the consent page only warns that the app is unverified.
3. Credentials, create **OAuth client ID**, type **Desktop app**. Note the client id and secret.
   Steps 2 to 4 only matter if the service account is ever abandoned; with one in place the
   OAuth keys are dead weight.
4. Refresh token: on the Mac, with the client id and secret in the environment,

        GOOGLE_ADS_CLIENT_ID=... GOOGLE_ADS_CLIENT_SECRET=... python3 apps/api/tools/google-ads-oauth.py

   Sign in as codemenschenapp@gmail.com.

   It opens the consent page, catches the redirect on localhost, and prints the refresh token to
   your terminal and nowhere else.
5. Cloud console, `cm-ops`, Google Ads API, **Access levels** (Manage). A project starts at
   **Test**, which only reaches test accounts. Apply for **Explorer**: it reaches production
   accounts, 2,880 operations a day, plenty for paused campaigns per order. Basic needs brand
   verification of the Cloud project first. Nothing in our code changes when a level lands.
   (API Center in Google Ads now only issues tokens for the App Conversion Tracking API.)
6. Note the 10-digit **customer id** of the ad account that will run campaigns (no dashes). A
   Manager account is optional: set its id as `login_customer_id` only when the signed-in Google
   account reaches the ad account through the Manager.

Appwerk runs on ad account **352-091-3982 "Codemenschen GmbH"** (EUR), connected 2026-09-22.
`GOOGLE_ADS_LOGIN_CUSTOMER_ID` is **empty**: the service account is a user on that account
itself, so there is no manager to sign in through. It held 963-722-5111 and that alone answered
USER_PERMISSION_DENIED long after the access was granted, because the header asked for a manager
the service account cannot see.

Two older accounts are noted here from 2026-09-15 and are not what the factory uses: Manager
669-088-3495 and ad account 577-053-2500.

Env keys:

    GOOGLE_ADS_CUSTOMER_ID            10 digits, the ad account
    GOOGLE_ADS_LOGIN_CUSTOMER_ID      10 digits, the Manager account (optional)
    GOOGLE_ADS_CLIENT_ID
    GOOGLE_ADS_CLIENT_SECRET
    GOOGLE_ADS_REFRESH_TOKEN

API version: `GOOGLE_ADS_API_VERSION`, default v25. Google retires a version about a year after
release and then answers 404 (v18 to v21 did by 2026-09); bump the default when that happens.
Check a version with a real token: without one, every version answers 401, even one that does
not exist (v26 looked alive that way on 2026-09-15 and was a 404 "Method not found").

## Connecting a customer's own ad account

A customer never repeats what we went through on 2026-09-22. Adding a user by hand hit an allowed
domain list, an approval by a second admin, and a reauth loop that never closed. Three hours for one
account. Instead, in their account settings in the portal, they paste one number and press one
button (`AdAccountController`, `Domain\Ads\AccountLink`, 2026-09-23).

**Google.** We send a link request from our manager account (`GOOGLE_ADS_MANAGER_ID`) to the ten
digits they gave us. It shows up in their own Google Ads under Admin, Access and security,
Managers, and they press accept. The link lives on Google's side, we store no credential of theirs,
and they can cut it there whenever they want. The request itself creates nothing that can spend.

**Meta** has no call that asks a business for access, so the customer adds our Business id
(`META_BUSINESS_ID`) as a partner in their own Business settings. We prove the link by reading
their ad account: if Meta answers, it is connected.

**Whose account a campaign runs on** (owner's decision 2026-09-23): once a customer has connected
their own account, their campaigns run there. Their card, their invoice, their spending limit.
Codemenschen does not front the money. `Domain\Ads\AdTarget` resolves it per campaign and falls
back to our own account for Appwerk's own ads and anything with no customer behind it. A Meta ad is
published by a page, so a connected Meta account without a page refuses to run rather than
publishing from ours.

**Our own account numbers are editable in the admin panel** (`Domain\Ads\AdSettings`), not only in
the server env: the manager account, the Business id, our ad account and page. They are numbers, not
credentials; tokens and the service account key file stay in the env and are never editable from a
browser. An empty field clears that number back to the env.

A link is only ever stored as `active` because the platform said so. Our own request succeeding
says nothing about whether the customer accepted, so `AccountLink::refresh()` asks and the
portal's "check status" button is what the customer presses after accepting.

The service account needs to be a user on the manager account for the Google request to go out at
all. It is a user on 352-091-3982 only, so this flow is configured but untested against a real
customer account.

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

## The ad reference library

Ads are catalogued by how they PERSUADE, not by what they are for, so they get their own
vocabulary and their own script. An app screen is an onboarding or a checkout; an ad is a price
anchor or a testimonial, and those are the seven angles the copywriter already writes to.

    python3 /var/lib/ai-factory/label-ad-library.py            # labels every unlabelled ad
    python3 /var/lib/ai-factory/label-ad-library.py --rebuild-only

Labels: angle (the seven in AdScriptWriter::ANGLES), industry, format, hook_position, text_load,
has_people, has_price, cta_words, notes. Format is measured from the aspect ratio rather than
asked, and AdFormats::shape() derives it the same way from a bought format, so the two cannot
drift. A test fails if the seven angles ever differ between the PHP and the Python.

Once ads are labelled, RenderProjectAd hands the copywriter one of the same angle, and the free
`ads` prototype gets any labelled one. No ad of that angle means no picture: a reference that
teaches the wrong shape is worse than none.

### What to scrape

Meta Ad Library, filtered. Thirty variants of one campaign teach one lesson; the app half of the
library is useful because it holds hundreds of different apps.

- Country Austria and Germany, not worldwide.
- Search by trade, not by brand: Friseur, Bäckerei, Physiotherapie, Tischlerei, Zahnarzt,
  Restaurant, Fitnessstudio.
- Prefer ads that have been running for months. A long-running ad is a profitable ad, and that is
  the only free quality signal the platform gives.
- Aim for roughly 200 ads from 50 different advertisers before treating this as a library.
