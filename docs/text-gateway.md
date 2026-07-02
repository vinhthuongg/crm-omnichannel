# Text.com Gateway Flow

CRM la ung dung duy nhat ket noi truc tiep voi Facebook Messenger. Text.com chi duoc dung nhu bot/agent engine phia sau CRM.

## Flow

```text
Facebook Messenger
-> Facebook webhook
-> CRM store message
-> CRM relay customer message to Text.com Customer Chat API
-> Text.com bot/agent replies
-> Text.com webhook / incoming_event
-> CRM stores bot message
-> CRM sends reply to Messenger by Facebook Graph API
-> CRM broadcasts realtime by Reverb
```

## Env

```env
TEXT_API_BASE_URL=https://api.livechatinc.com/v3.6
TEXT_BRIDGE_ENABLED=true
TEXT_CLIENT_ID=your_text_app_client_id
TEXT_CLIENT_SECRET=your_text_app_client_secret
TEXT_REDIRECT_URI="${APP_URL}/auth/text/callback"
TEXT_ORGANIZATION_ID=your_text_organization_id
TEXT_AGENT_EMAIL=agent@example.com
TEXT_API_TOKEN=your_text_personal_access_token
TEXT_AGENT_ACCESS_TOKEN=oauth_agent_access_token_with_customers_own
TEXT_AGENT_REFRESH_TOKEN=oauth_refresh_token
TEXT_AGENT_TOKEN_EXPIRES_AT=2026-07-02T12:00:00+07:00
TEXT_MESSENGER_APP_ID=optional_text_messenger_app_id_for_echo_detection
TEXT_DEFAULT_GROUP_ID=0
TEXT_HUMAN_GROUP_ID=0
TEXT_WEBHOOK_SECRET=change_this_secret
```

`TEXT_DEFAULT_GROUP_ID` la group ma CRM tao chat Text.com vao. `TEXT_HUMAN_GROUP_ID` chi dung cho tinh nang transfer sang nhan vien Text.com neu can.

`TEXT_API_TOKEN` la Personal Access Token dung cho Agent Chat API. `TEXT_AGENT_ACCESS_TOKEN` la OAuth agent access token co scope `customers:own`; token nay bat buoc de CRM tao customer access token va gui tin nhu khach hang qua Customer Chat API. Khong dung PAT cho `TEXT_AGENT_ACCESS_TOKEN`.

## OAuth setup

Trong Text.com Developer Console, cau hinh OAuth client:

```text
Client type: Server-side app
Redirect URI whitelist: https://your-domain.com/auth/text/callback
Scopes: customers:own, chats--all:rw, chats--access:rw
```

Sau khi dien `TEXT_CLIENT_ID`, `TEXT_CLIENT_SECRET`, `TEXT_ORGANIZATION_ID`, dang nhap CRM bang Admin va mo:

```text
https://your-domain.com/auth/text
```

CRM se doi authorization code lay agent access token va refresh token, sau do luu vao `.env`.

## Text.com webhook

Set webhook URL:

```text
https://your-domain.com/api/webhook/text?token=TEXT_WEBHOOK_SECRET
```

Hoac gui header:

```text
X-Text-Webhook-Secret: TEXT_WEBHOOK_SECRET
```

Webhook can nhan cac event/push tu Text.com, dac biet:

- `incoming_chat`
- `incoming_event`

## Important

Khong ket noi Text.com truc tiep vao Facebook Page Messenger khi dung gateway nay. Neu Text.com van la app Messenger truc tiep, Facebook co the bao loi:

```text
(#10) another app currently controls this thread
```

CRM se gan `custom_id` bat dau bang `crm_` cho cac event no gui sang Text.com. Khi Text.com webhook tra echo ve, CRM bo qua cac event nay de tranh lap tin nhan.
