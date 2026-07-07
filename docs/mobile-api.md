# CRM Omnichannel Mobile API

Tai lieu nay la hop dong API chuan cho app dien thoai. API uu tien JSON, token Bearer, phan quyen theo vai tro Admin va CSKH, va khong phu thuoc vao web route Blade.

## Nguyen tac chung

Base URL:

```text
https://oldthread.store/api
```

Header bat buoc:

```http
Accept: application/json
Authorization: Bearer {access_token}
```

Quy uoc response thanh cong:

```json
{
  "data": {},
  "meta": {}
}
```

Quy uoc loi:

```json
{
  "message": "Noi dung loi",
  "errors": {}
}
```

Ma loi chinh:

```text
401 Chua dang nhap hoac token het han
403 Khong co quyen
404 Khong tim thay
409 Xung dot nghiep vu
422 Du lieu khong hop le
500 Loi he thong
```

## Phan quyen

Admin:

- Quan ly tai khoan nhan vien.
- Quan ly kenh Facebook/Zalo.
- Quan ly ca truc.
- Xem tat ca hoi thoai.
- Gan/chuyen hoi thoai.
- Xem bao cao, hoat dong, thong bao he thong.

CSKH:

- Xem hoi thoai duoc giao, da xu ly, hoac thuoc ca truc hien tai.
- Nhan xu ly hoi thoai.
- Gui tin nhan khi duoc giao hoi thoai.
- Xem/sua thong tin khach trong hoi thoai duoc phep.
- Xem thong bao cua chinh minh.
- Doi mat khau va cai dat ca nhan.

## 1. Auth

### Dang nhap

```http
POST /auth/login
```

Request:

```json
{
  "email": "admin@example.com",
  "password": "password",
  "device_name": "ios"
}
```

Response:

```json
{
  "data": {
    "token_type": "Bearer",
    "access_token": "...",
    "user": {
      "id": 1,
      "name": "Admin",
      "email": "admin@example.com",
      "roles": ["Admin"],
      "permissions": []
    }
  }
}
```

### Lay profile

```http
GET /auth/profile
```

### Refresh token

```http
POST /auth/refresh
```

### Doi mat khau

```http
PUT /auth/password
```

Request:

```json
{
  "current_password": "old-password",
  "password": "new-password",
  "password_confirmation": "new-password"
}
```

### Dang xuat

```http
POST /auth/logout
```

## 2. App Bootstrap

Can them API nay de app mo len co du thong tin menu, quyen, kenh realtime, cau hinh upload.

```http
GET /mobile/bootstrap
```

Response de xuat:

```json
{
  "data": {
    "user": {},
    "roles": ["CSKH"],
    "permissions": [],
    "navigation": ["conversations", "notifications", "settings"],
    "features": {
      "facebook": true,
      "zalo": false,
      "reply_suggestions": true,
      "attachments": true
    },
    "realtime": {
      "driver": "reverb",
      "host": "oldthread.store",
      "scheme": "wss",
      "key": "crm-key"
    }
  }
}
```

Trang thai: can bo sung.

## 3. Conversations

### Danh sach hoi thoai

```http
GET /conversations
```

Query de xuat:

```text
channel=facebook|zalo|all
status=waiting|in_progress|resolved|closed
assigned=me|unassigned|all
tag_id=1
q=tu khoa
after_id=100
per_page=20
```

Response:

```json
{
  "data": [
    {
      "id": 1,
      "status": "waiting",
      "assigned_to": null,
      "customer": {
        "id": 1,
        "name": "Nguyen Van A",
        "avatar": null,
        "phone": "093..."
      },
      "assignee": null,
      "tags": [],
      "last_message_at": "2026-07-07T09:00:00Z",
      "unread_messages_count": 1
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "has_more": true
  }
}
```

Trang thai: da co co ban.

### Chi tiet hoi thoai

```http
GET /conversations/{conversation}
```

Can tra:

- customer
- assignee
- tags
- conversation status
- summary
- notes gan day
- messages moi nhat

Trang thai: da co co ban, can chuan hoa summary/notes cho mobile.

### Nhan xu ly

```http
POST /conversations/{conversation}/claim
```

Trang thai: can bo sung API, hien dang co web route.

### Gan nhan vien

```http
POST /conversations/{conversation}/assign
```

Request:

```json
{
  "assigned_to": 2
}
```

Trang thai: da co.

### Chuyen hoi thoai

```http
POST /conversations/{conversation}/transfer
```

Request:

```json
{
  "assigned_to": 3
}
```

Trang thai: da co.

### Bo gan

```http
POST /conversations/{conversation}/release
```

Trang thai: da co.

### Cap nhat trang thai hoi thoai

Trang thai hoi thoai khac voi tag khach hang:

```text
waiting      Khach doi
in_progress  Dang tu van
resolved     Da xu ly
closed       Da dong
reopened     Mo lai
```

API:

```http
POST /conversations/{conversation}/resolve
POST /conversations/{conversation}/close
POST /conversations/{conversation}/reopen
```

Trang thai: da co.

### Danh dau da doc

```http
POST /conversations/{conversation}/read
```

Trang thai: can bo sung API, hien dang co web route.

## 4. Messages

### Lay tin nhan

```http
GET /conversations/{conversation}/messages
```

Query:

```text
before_id=100
after_id=100
per_page=20
```

Luu y mobile:

- Mac dinh lay 20 tin moi nhat.
- Keo len thi dung `before_id`.
- Realtime nhan tin moi qua WebSocket.

Trang thai: da co co ban.

### Gui tin nhan text

```http
POST /conversations/{conversation}/messages
```

Request:

```json
{
  "channel": "facebook",
  "content": "Em chao anh/chị",
  "message_type": "text"
}
```

Trang thai: da co.

### Gui thi tham noi bo

```http
POST /conversations/{conversation}/messages
```

Request de xuat:

```json
{
  "channel": "internal",
  "content": "Khach nay dang quan tam tra gop",
  "message_type": "whisper"
}
```

Trang thai: can kiem tra lai authorize va resource.

### Upload file/anh/video

```http
POST /conversations/{conversation}/attachments
Content-Type: multipart/form-data
```

Form:

```text
attachments[] = file
channel = facebook|zalo
```

Response de xuat:

```json
{
  "data": [
    {
      "name": "image.jpg",
      "type": "image",
      "url": "https://..."
    }
  ]
}
```

Trang thai: can bo sung API, hien dang co web route.

### Thu hoi/xoa tin nhan

```http
PATCH  /conversations/{conversation}/messages/{message}/recall
DELETE /conversations/{conversation}/messages/{message}
DELETE /conversations/{conversation}/messages
```

Trang thai: can bo sung API neu app can.

## 5. Reply Suggestions

### Lay goi y cau tra loi

```http
GET /conversations/{conversation}/reply-suggestions
```

Response:

```json
{
  "data": [
    {
      "id": 1,
      "content": "Anh/chị muốn em báo giá phiên bản nào của Vios ạ?",
      "source": "ai",
      "generated_at": "2026-07-07T09:00:00Z"
    }
  ]
}
```

Trang thai: can bo sung API, hien dang co web route.

## 6. Customers

### Danh sach khach hang

```http
GET /customers
```

Query:

```text
q=tu khoa vector
channel=facebook|zalo
tag_id=1
assigned_to=2
status=in_progress
from=2026-07-01
to=2026-07-07
per_page=20
```

Luu y:

- Search nen dung vector search.
- Khong reload toan trang.

Trang thai: da co API co ban, can chuan hoa vector search query.

### Chi tiet khach hang

```http
GET /customers/{customer}
```

### Cap nhat khach hang

```http
PATCH /customers/{customer}
```

Request:

```json
{
  "name": "Nguyen Van A",
  "phone": "0936778029",
  "email": "a@example.com"
}
```

Trang thai: da co.

### Ghi chu khach hang

```http
GET  /customers/{customer}/notes
POST /customers/{customer}/notes
```

Request:

```json
{
  "content": "Khach quan tam Vios tra gop"
}
```

Trang thai: can bo sung API, hien dang co web route theo conversation.

### Tag khach hang

Tag khach hang la muc quan tam, khong phai trang thai hoi thoai.

Vi du:

```text
Bao gia
Tra gop
Lai thu
Bao duong
Dat lich
Da co SDT
```

API de xuat:

```http
GET    /customer-tags
POST   /customer-tags
PATCH  /customer-tags/{tag}
DELETE /customer-tags/{tag}
POST   /customers/{customer}/tags
DELETE /customers/{customer}/tags/{tag}
```

Trang thai: can bo sung API.

## 7. Conversation Tags

Conversation status mac dinh:

```text
Dang tu van
Khach doi
```

Custom tag cua hoi thoai:

```http
GET    /conversation-tags
POST   /conversation-tags
PATCH  /conversation-tags/{tag}
DELETE /conversation-tags/{tag}
POST   /conversations/{conversation}/tags
```

Trang thai: can bo sung API chuan, hien dang co web route.

## 8. Channels

### Danh sach kenh da ket noi

```http
GET /channels
```

Response de xuat:

```json
{
  "data": [
    {
      "id": 1,
      "type": "facebook",
      "name": "Old Thread",
      "page_id": "950...",
      "avatar": null,
      "status": "connected",
      "token_status": "valid",
      "subscribed_at": "2026-07-07T09:00:00Z",
      "last_sync_at": null
    }
  ]
}
```

Trang thai: can bo sung API.

### Facebook login/connect page

App mobile nen mo WebView:

```http
GET /auth/facebook
GET /auth/facebook/callback
```

Sau khi login:

```http
GET  /facebook/pages
POST /facebook/connect-page
POST /facebook/pages/{facebookPage}/sync-messages
```

Trang thai: hien la web route, can them JSON API/mobile callback neu app can native flow.

### Zalo OA

Can thiet ke rieng neu app mobile can quan ly ket noi Zalo.

## 9. Work Shifts

### Danh sach ca truc

```http
GET /work-shifts
```

Query:

```text
date=2026-07-07
status=current|upcoming|past|all
```

### Tao ca truc

```http
POST /work-shifts
```

Request:

```json
{
  "name": "Ca sang",
  "starts_at": "08:00",
  "ends_at": "09:00",
  "agent_ids": [2, 3],
  "is_active": true
}
```

### Cap nhat/xoa

```http
PATCH  /work-shifts/{workShift}
DELETE /work-shifts/{workShift}
```

### Nhan su kha dung

```http
GET /work-shifts/available-agents?starts_at=08:00&ends_at=09:00
```

Trang thai: can bo sung API, hien dang co web route.

## 10. Users / Agents

```http
GET    /users
POST   /users
GET    /users/{user}
PATCH  /users/{user}
```

Request tao nhan vien:

```json
{
  "name": "CSKH 01",
  "email": "cskh01@example.com",
  "password": "password",
  "role": "CSKH",
  "is_active": true
}
```

Trang thai: da co API co ban.

## 11. Dashboard

```http
GET /dashboard/overview
```

Can tra so lieu theo conversation, khong theo message:

- Tong hoi thoai.
- Hoi thoai dang xu ly.
- Khach hang moi.
- SDT da thu thap.
- Khach yeu cau bao gia.
- Yeu cau lai thu.
- Quan tam tra gop.
- Dat lich bao duong.
- Nguon khach hang.
- Hieu suat nhan vien.

Trang thai: da co API co ban, can chuan hoa metric neu app can dashboard day du.

## 12. Notifications

```http
GET  /notifications
POST /notifications/{id}/read
POST /notifications/read-all
```

Response de xuat:

```json
{
  "data": [
    {
      "id": "uuid",
      "type": "new_message",
      "title": "Tin nhan moi",
      "body": "Khach hang vua nhan tin",
      "read_at": null,
      "created_at": "2026-07-07T09:00:00Z",
      "payload": {
        "conversation_id": 1
      }
    }
  ]
}
```

Trang thai: da co mot phan, can them read-all API mobile.

## 13. Activity Log

```http
GET /activity-logs
```

Query:

```text
agent_id=2
type=message|assignment|customer|system
from=2026-07-01
to=2026-07-07
q=tu khoa
```

Trang thai: da co API co ban.

## 14. Realtime cho mobile

Khuyen nghi:

1. Mobile login lay token.
2. Goi `/mobile/bootstrap` lay config Reverb.
3. Ket noi WebSocket Reverb.
4. Auth private channel bang:

```http
POST /broadcasting/auth
Authorization: Bearer {access_token}
```

Kenh de xuat:

```text
private-users.{user_id}
private-conversations.{conversation_id}
private-inbox.{user_id}
```

Event can mobile xu ly:

```text
message.created
message.updated
message.deleted
conversation.assigned
conversation.claimed
conversation.updated
notification.created
```

Trang thai: Reverb da co, can viet tai lieu channel/event chuan va dam bao auth bang Bearer token cho mobile.

## 15. API can bo sung truoc khi lam app mobile

Muc do bat buoc:

1. `GET /mobile/bootstrap`
2. `POST /conversations/{conversation}/claim`
3. `POST /conversations/{conversation}/read`
4. `POST /conversations/{conversation}/attachments`
5. `GET /conversations/{conversation}/reply-suggestions`
6. `GET/POST/PATCH/DELETE /conversation-tags`
7. `GET/POST /customers/{customer}/notes`
8. `GET /channels`
9. `GET/POST/PATCH/DELETE /work-shifts`
10. `POST /notifications/read-all`

Muc do nen co:

1. `GET /customers/search?q=` dung vector.
2. `GET /work-shifts/available-agents`
3. `GET /reports/agents`
4. `GET /reports/conversations`
5. `POST /channels/facebook/sync`

## 16. Thu tu trien khai de app mobile chay nhanh

Phase 1:

- Auth.
- Bootstrap.
- Conversations list/detail.
- Messages list/send.
- Mark read.
- Notifications.

Phase 2:

- Upload attachments.
- Customer detail/update/notes.
- Tags/status.
- Reply suggestions.

Phase 3:

- Channel management.
- Work shifts.
- Dashboard/report.
- Realtime event contract day du.

