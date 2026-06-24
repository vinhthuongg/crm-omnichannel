# Facebook Login and Messenger Flow

The CRM now uses one Meta app for both Facebook Login and Messenger.

```text
Meta App = Facebook Login + Messenger
CRM = data mapping layer
```

## App Responsibilities

The single Meta app is used for:

- Facebook OAuth login.
- `/me?fields=id,name,email`.
- `/me/accounts` to load managed pages.
- Page access token storage.
- `/{page-id}/subscribed_apps`.
- `/me/messages`.
- `/{page-id}/conversations`.
- Messenger webhook receive.
- Customer profile lookup.

## Main Flow

```text
User
|
v
Facebook Login
|
v
email + public_profile + pages_* + pages_messaging
|
v
User Access Token
|
v
GET /me
|
v
Save facebook_accounts
|
v
GET /me/accounts
|
v
Show fanpages in CRM
|
v
User chooses fanpage
|
v
Save page_id, page_name, page_access_token, facebook_user_id, messenger_app_id
|
v
POST /{page-id}/subscribed_apps
|
v
Webhook
|
v
Conversation
|
v
Realtime CRM
```

## Required Env

```env
FACEBOOK_CLIENT_ID=
FACEBOOK_CLIENT_SECRET=
FACEBOOK_REDIRECT_URI="${APP_URL}/auth/facebook/callback"
FACEBOOK_LOGIN_CONFIG_ID=
FACEBOOK_LOGIN_SCOPES=email,public_profile,pages_show_list,pages_manage_metadata,pages_read_engagement,pages_messaging

MESSENGER_APP_ID="${FACEBOOK_CLIENT_ID}"
MESSENGER_APP_SECRET="${FACEBOOK_CLIENT_SECRET}"
```

`MESSENGER_APP_ID` is kept as an internal guard for existing Messenger code. It should match `FACEBOOK_CLIENT_ID` in the one-app flow.

## Token Validation

Before CRM calls Messenger APIs with a stored page, it checks that:

- The token is valid.
- The token app id matches the configured app id.
- The stored page `messenger_app_id` matches `MESSENGER_APP_ID`.

This prevents:

- Invalid OAuth access token.
- App ID mismatch.
- Application does not own the object.

## Tenant Boundary

Conversations store `facebook_page_id`.

Admin users with the existing database login and `conversation.view_all` permission keep the old behavior and can see all conversations.

Facebook users only see conversations where `conversations.facebook_page_id` belongs to one of their connected rows in `facebook_pages`.

## Realtime CRM

Webhook events arrive from Messenger, are stored as Messenger messages, then broadcast through the existing Reverb/WebSocket flow.
