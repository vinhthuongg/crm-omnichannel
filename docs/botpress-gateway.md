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

Trong Botpress, uu tien cai Chat integration cho bot va lay Webhook ID tu URL:

```text
https://chat.botpress.cloud/{BOTPRESS_WEBHOOK_ID}
```

Neu muon bao mat hon, dat Encryption Key trong Chat integration va dien cung key vao `.env`.

Neu ban chi co URL dang `https://webhook.botpress.cloud/...`, CRM se goi truc tiep URL do. Mode nay chi gui duoc reply ve Messenger khi workflow Botpress tra ve JSON response ngay trong request, vi day khong phai Chat API message polling.

## .env

```env
BOTPRESS_ENABLED=true
BOTPRESS_API_KEY=your_botpress_api_key
BOTPRESS_WEBHOOK_URL=https://webhook.botpress.cloud/your_webhook_id
BOTPRESS_BASE_URL=https://chat.botpress.cloud
BOTPRESS_WEBHOOK_ID=your_botpress_chat_webhook_id
BOTPRESS_ENCRYPTION_KEY=optional_same_key_as_chat_integration
BOTPRESS_RESPONSE_POLL_ATTEMPTS=8
BOTPRESS_RESPONSE_POLL_DELAY_MS=700
```

Voi Chat integration, dien `BOTPRESS_WEBHOOK_ID` hoac `BOTPRESS_WEBHOOK_URL=https://chat.botpress.cloud/{id}`.

Voi direct webhook, dien `BOTPRESS_WEBHOOK_URL=https://webhook.botpress.cloud/{id}`. Workflow Botpress can response mot trong cac dang sau:

```json
{"text":"Noi dung bot tra loi"}
```

```json
{"reply":"Noi dung bot tra loi"}
```

```json
{"messages":[{"payload":{"text":"Noi dung bot tra loi"}}]}
```

Neu workflow Botpress khong tra response ngay, hay cho Botpress goi nguoc ve CRM sau khi tao cau tra loi:

```text
POST https://oldthread.store/api/webhook/botpress?secret={BOTPRESS_CALLBACK_SECRET}
```

Payload toi thieu:

```json
{
  "conversationId": "crm_conversation_1",
  "text": "Noi dung bot tra loi"
}
```

CRM cung chap nhan `metadata.crm_conversation_id`, `crm_conversation_id`, `reply`, `message`, hoac `messages[0].payload.text`.

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
