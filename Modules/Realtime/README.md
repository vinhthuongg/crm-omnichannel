# Realtime Module

Laravel Reverb is wired through `Modules\Message\Events\NewMessageEvent`.

Broadcast channels:
- `private-crm.conversations`
- `private-crm.conversation.{conversationId}`