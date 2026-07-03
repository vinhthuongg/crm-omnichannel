# Botpress gateway

CRM la trung gian giua Facebook Messenger va Botpress.

```text
Facebook Messenger
-> Meta webhook
-> CRM luu customer / conversation / message
-> CRM goi Botpress Chat API
-> CRM luu cau tra loi cua bot
-> CRM gui lai khach bang Facebook Send API
```

Khong ket noi truc tiep Botpress voi Facebook Page. Cach nay giu CRM la nguon du lieu chinh va tranh loi Meta Handover Protocol.

## Cau hinh Botpress

Trong Botpress, cai Chat integration cho bot va lay Webhook ID tu URL:

```text
https://chat.botpress.cloud/{BOTPRESS_WEBHOOK_ID}
```

Neu muon bao mat hon, dat Encryption Key trong Chat integration va dien cung key vao `.env`.

## .env

```env
BOTPRESS_ENABLED=true
BOTPRESS_BASE_URL=https://chat.botpress.cloud
BOTPRESS_WEBHOOK_ID=your_botpress_chat_webhook_id
BOTPRESS_ENCRYPTION_KEY=optional_same_key_as_chat_integration
BOTPRESS_RESPONSE_POLL_ATTEMPTS=8
BOTPRESS_RESPONSE_POLL_DELAY_MS=700
```

Sau khi sua env:

```bash
php artisan optimize:clear
supervisorctl restart crm-queue:*
```

## Cach hoat dong

- Tin nhan khach tu Facebook duoc luu vao CRM truoc.
- Job `RelayInboundMessageToBotpressJob` gui text sang Botpress Chat API.
- Botpress tra loi trong cung conversation context.
- CRM luu reply voi `sender_type=system`, `client_message_id=botpress_{id}`.
- `SendOutboundMessageJob` gui reply do sang Facebook.
- Khi nhan vien gui tin trong CRM, `ConversationService::recordOutboundMessage()` gan `automation_state.paused_by_user_at`; cac tin khach sau do se khong relay sang Botpress nua.

## Test nhanh

```bash
cd /var/www/crm-omnichannel
php artisan migrate
php artisan optimize:clear
supervisorctl restart crm-queue:*
tail -f storage/logs/laravel.log | grep -E "Botpress|Queued outbound|Outbound"
```
