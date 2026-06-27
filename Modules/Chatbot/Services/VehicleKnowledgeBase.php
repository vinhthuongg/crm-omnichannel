<?php

namespace Modules\Chatbot\Services;

class VehicleKnowledgeBase
{
    public function documents(): array
    {
        return array_values(array_merge(
            $this->priceDocuments(),
            $this->promotionDocuments(),
        ));
    }

    public function quickReplies(): array
    {
        return [
            ['content_type' => 'text', 'title' => 'Báo giá lăn bánh', 'payload' => 'PRICE_BY_AREA'],
            ['content_type' => 'text', 'title' => 'Ưu đãi hiện hành', 'payload' => 'PROMOTIONS'],
            ['content_type' => 'text', 'title' => 'Vay trả góp', 'payload' => 'INSTALLMENT_LOAN'],
            ['content_type' => 'text', 'title' => 'Tình trạng xe', 'payload' => 'VEHICLE_AVAILABILITY'],
            ['content_type' => 'text', 'title' => 'Chọn phiên bản', 'payload' => 'VERSION_CONSULTING'],
        ];
    }

    public function flow(string $payload): ?array
    {
        return [
            'PRICE_BY_AREA' => [
                'label' => 'Bao gia lan banh',
                'question' => 'Anh/Chị cần em báo giá lăn bánh mẫu xe gì ạ?',
                'search_prefix' => 'giá xe báo giá lăn bánh',
            ],
            'PROMOTIONS' => [
                'label' => 'Uu dai hien hanh',
                'question' => 'Anh/Chị quan tâm mẫu xe nào để em kiểm tra chương trình ưu đãi hiện hành ạ?',
                'search_prefix' => 'khuyến mãi ưu đãi chương trình',
            ],
            'INSTALLMENT_LOAN' => [
                'label' => 'Vay tra gop',
                'question' => 'Anh/Chị muốn tư vấn trả góp mẫu xe nào và dự kiến trả trước khoảng bao nhiêu ạ?',
                'search_prefix' => 'vay trả góp giá xe',
            ],
            'VEHICLE_AVAILABILITY' => [
                'label' => 'Tinh trang xe',
                'question' => 'Anh/Chị muốn kiểm tra tình trạng xe, màu xe và thời gian giao xe của mẫu nào ạ?',
                'search_prefix' => 'màu xe tình trạng xe giao xe',
            ],
            'VERSION_CONSULTING' => [
                'label' => 'Tu van phien ban',
                'question' => 'Anh/Chị đang quan tâm mẫu xe nào và nhu cầu sử dụng chính là gì ạ?',
                'search_prefix' => 'tư vấn phiên bản mẫu xe grade',
            ],
        ][$payload] ?? null;
    }

    private function priceDocuments(): array
    {
        return collect($this->json('giaxe_json.txt'))
            ->map(function (array $row): array {
                $model = $this->value($row, 'model');
                $grade = $this->value($row, 'grade');
                $color = $this->value($row, 'mau_xe');
                $price = $this->value($row, 'gia_ban_le_niem_yet');

                return [
                    'id' => 'price:'.md5(json_encode($row)),
                    'source' => 'giaxe_json',
                    'metadata' => [
                        'model' => $model,
                        'grade' => $grade,
                        'color' => $color,
                        'price' => $price,
                    ],
                    'text' => trim("Bảng giá xe Toyota Kiên Giang: {$model} {$grade}, màu {$color}, mã màu ".$this->value($row, 'ma_mau').", giá bán lẻ niêm yết {$price} đồng. CKD/CBU: ".$this->value($row, 'ckd_cbu').'.'),
                ];
            })
            ->filter(fn (array $doc): bool => $doc['text'] !== '')
            ->values()
            ->all();
    }

    private function promotionDocuments(): array
    {
        $payload = $this->json('ctkm_json.txt');
        $program = $this->value($payload, 'ten_chuong_trinh');
        $updatedAt = $this->value($payload, 'ngay_cap_nhat');

        return collect((array) data_get($payload, 'danh_sach_khuyen_mai', []))
            ->map(function (array $row) use ($program, $updatedAt): array {
                $model = $this->value($row, 'dong_xe');
                $grade = $this->value($row, 'ma_ban');
                $discount = (int) ($row['giam_gia_tien_mat'] ?? 0);
                $insurance = $this->value($row, 'bao_hiem') ?: 'không có thông tin bảo hiểm';
                $gifts = implode(', ', array_filter((array) ($row['qua_tang_phu_kien'] ?? [])));
                $note = $this->value($row, 'ghi_chu');

                return [
                    'id' => 'promotion:'.md5(json_encode($row)),
                    'source' => 'ctkm_json',
                    'metadata' => [
                        'program' => $program,
                        'updated_at' => $updatedAt,
                        'model' => $model,
                        'grade' => $grade,
                        'discount' => $discount,
                    ],
                    'text' => trim("Chương trình khuyến mãi Toyota Kiên Giang {$program}, cập nhật {$updatedAt}: {$model} {$grade}, giảm giá tiền mặt ".number_format($discount, 0, ',', '.').' đồng, bảo hiểm '.$insurance.', quà tặng phụ kiện '.($gifts ?: 'không có thông tin').($note ? ', ghi chú '.$note : '').'.'),
                ];
            })
            ->filter(fn (array $doc): bool => $doc['text'] !== '')
            ->values()
            ->all();
    }

    private function json(string $filename): array
    {
        $path = base_path('Modules/Chatbot/Data/'.$filename);

        if (! is_file($path)) {
            return [];
        }

        $contents = trim((string) file_get_contents($path));
        $decoded = json_decode($contents, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strcspn($contents, '[{');
        $end = max(strrpos($contents, ']') ?: 0, strrpos($contents, '}') ?: 0);

        if ($end > $start) {
            return json_decode(substr($contents, $start, $end - $start + 1), true) ?: [];
        }

        return [];
    }

    private function value(array $row, string $key): string
    {
        return trim((string) ($row[$key] ?? ''));
    }
}
