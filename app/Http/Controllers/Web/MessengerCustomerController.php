<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\ConversationInsightSummaryService;
use App\Services\MessengerCustomerPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Conversation\Models\Conversation;
use Modules\Conversation\Services\ConversationTagCatalogService;
use Modules\Customer\Services\MessengerCustomerService;

class MessengerCustomerController extends Controller
{
    /** Nhận MessengerCustomerService để tạo và cập nhật hồ sơ khách hàng; ConversationInsightSummaryService để tóm tắt nhu cầu và thông tin khách hàng; ConversationTagCatalogService để cung cấp danh mục nhãn mặc định; MessengerCustomerPresenter để định dạng dữ liệu đầu ra. */
    public function __construct(
        private readonly MessengerCustomerService $customers,
        private readonly ConversationInsightSummaryService $insights,
        private readonly ConversationTagCatalogService $tagCatalog,
        private readonly MessengerCustomerPresenter $presenter,
    ) {
    }

    /** Cập nhật hồ sơ khách hàng trong hội thoại và trả summary/insight mới cho sidebar. */
    public function update(Request $request, Conversation $conversation): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);
        $conversation = $this->customers->update($request->user(), $conversation, $data);

        return response()->json(['data' => [
            'id' => (int) $conversation->id,
            'customer_name' => $conversation->customer?->name ?? 'Customer',
            'customer_avatar' => $conversation->customer?->avatar,
            'customer_phone' => $conversation->customer?->phone,
            'customer_email' => $conversation->customer?->email,
            'facebook_profile_url' => $this->presenter->facebookProfileUrl($conversation),
            'customer_contact' => $this->presenter->contact($conversation),
            'customer_public_details' => $this->presenter->publicDetails($conversation),
            'conversation_summary' => $this->insights->summarize($conversation),
        ]]);
    }

    /** Lưu ghi chú nội bộ cho khách hàng và trả danh sách ghi chú mới nhất. */
    public function storeNote(Request $request, Conversation $conversation): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $conversation = $this->customers->addNote($request->user(), $conversation, $data['body']);

        return response()->json(['data' => ['notes' => $this->presenter->notes($conversation)]], 201);
    }

    /** Tạo hoặc lấy nhãn theo tên, gắn vào khách hàng và trả danh mục nhãn mới. */
    public function storeTag(Request $request, Conversation $conversation): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'color' => ['nullable', 'string', 'max:24']]);
        $conversation = $this->customers->addTag($request->user(), $conversation, $data['name']);

        return response()->json(['data' => [
            'tags' => $this->presenter->tags($conversation),
            'all_tags' => $this->tagCatalog->payloads(),
        ]], 201);
    }

}
