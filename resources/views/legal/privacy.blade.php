@component('legal.partials.layout', ['title' => 'Chính sách quyền riêng tư'])
    <h1>Chính sách quyền riêng tư</h1>
    <p class="meta">Cập nhật ngày 08/07/2026</p>

    <p>
        Omnichannel CRM được sử dụng để tiếp nhận và xử lý hội thoại khách hàng từ các kênh như Facebook Messenger và Zalo OA.
        Chúng tôi tôn trọng quyền riêng tư của khách hàng và chỉ xử lý dữ liệu cần thiết để tư vấn, chăm sóc và hỗ trợ dịch vụ.
    </p>

    <h2>Dữ liệu chúng tôi có thể thu thập</h2>
    <ul>
        <li>Thông tin hồ sơ công khai từ kênh nhắn tin, ví dụ tên hiển thị, ảnh đại diện hoặc mã định danh hội thoại.</li>
        <li>Nội dung tin nhắn, tệp đính kèm và lịch sử trao đổi giữa khách hàng với nhân viên tư vấn.</li>
        <li>Thông tin khách hàng chủ động cung cấp, ví dụ số điện thoại, email, nhu cầu tư vấn, lịch hẹn hoặc mẫu xe quan tâm.</li>
        <li>Thông tin vận hành nội bộ như nhân viên phụ trách, trạng thái hội thoại, ghi chú chăm sóc và thời gian xử lý.</li>
    </ul>

    <h2>Mục đích sử dụng dữ liệu</h2>
    <ul>
        <li>Phản hồi tin nhắn và hỗ trợ khách hàng đúng nhu cầu.</li>
        <li>Phân công nhân viên chăm sóc khách hàng và theo dõi tiến độ xử lý hội thoại.</li>
        <li>Lưu lịch sử tư vấn để hỗ trợ khách hàng nhất quán ở các lần liên hệ sau.</li>
        <li>Thống kê hiệu suất vận hành CRM, chất lượng phản hồi và nhu cầu khách hàng.</li>
    </ul>

    <h2>Chia sẻ dữ liệu</h2>
    <p>
        Dữ liệu khách hàng chỉ được sử dụng trong phạm vi vận hành chăm sóc khách hàng. Chúng tôi không bán dữ liệu cá nhân cho bên thứ ba.
        Một số dữ liệu có thể được xử lý bởi nền tảng kỹ thuật được dùng để vận hành hệ thống, ví dụ máy chủ lưu trữ, Facebook, Zalo hoặc dịch vụ tự động hóa hội thoại.
    </p>

    <h2>Bảo mật</h2>
    <p>
        Hệ thống sử dụng phân quyền tài khoản, đăng nhập bảo vệ và giới hạn quyền truy cập theo vai trò. Nhân viên chỉ được xem dữ liệu trong phạm vi được phân công hoặc được cấp quyền.
    </p>

    <h2>Liên hệ</h2>
    <p>
        Nếu cần yêu cầu chỉnh sửa hoặc xóa dữ liệu, vui lòng gửi yêu cầu qua trang <a href="{{ route('legal.data-deletion') }}">hướng dẫn xóa dữ liệu</a>.
    </p>
@endcomponent
