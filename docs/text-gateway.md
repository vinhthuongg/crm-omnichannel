# Text.com Gateway Flow

CRM la ung dung duy nhat ket noi truc tiep voi Facebook Messenger. Text.com chi duoc dung nhu bot/agent engine phia sau CRM.

## Flow

```text
Facebook Messenger
-> Facebook webhook
-> CRM store message
-> CRM relay customer message to Text.com Agent Chat API
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
TEXT_AGENT_EMAIL=agent@example.com
TEXT_API_TOKEN=your_text_personal_access_token
TEXT_DEFAULT_GROUP_ID=0
TEXT_HUMAN_GROUP_ID=0
TEXT_WEBHOOK_SECRET=change_this_secret
```

`TEXT_DEFAULT_GROUP_ID` la group ma CRM tao chat Text.com vao. `TEXT_HUMAN_GROUP_ID` chi dung cho tinh nang transfer sang nhan vien Text.com neu can.

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
