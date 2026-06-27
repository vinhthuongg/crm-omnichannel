<?php

namespace Modules\Chatbot\Services;

class VehicleKnowledgeBase
{
    public function documents(): array
    {
        return array_values(array_merge(
            $this->priceDocuments(),
            $this->promotionDocuments(),
            $this->installmentDocuments(),
        ));
    }

    public function contextDocuments(string $query, ?string $topic = null): array
    {
        $documents = collect($this->documents());
        $matchedModels = collect($this->detectModels($query));

        if ($matchedModels->isEmpty()) {
            return [];
        }

        $matched = $documents->filter(function (array $document) use ($matchedModels): bool {
            return $matchedModels->contains((string) data_get($document, 'metadata.model'));
        });

        $prices = $matched
            ->where('source', 'giaxe_json')
            ->unique(fn (array $document): string => implode('|', [
                data_get($document, 'metadata.model'),
                data_get($document, 'metadata.grade'),
                data_get($document, 'metadata.price'),
            ]))
            ->take(10);

        $promotions = $matched
            ->where('source', 'ctkm_json')
            ->take(8);

        $installments = $matched
            ->where('source', 'ctrinh_tragop')
            ->take(8);

        return match ($topic) {
            'PROMOTIONS' => $promotions->merge($prices)->values()->all(),
            'INSTALLMENT_LOAN' => $installments->merge($prices)->merge($promotions)->values()->all(),
            default => $prices->merge($promotions)->values()->all(),
        };
    }

    public function detectModels(string $text): array
    {
        $normalizedText = $this->normalize($text);

        return collect($this->documents())
            ->pluck('metadata.model')
            ->filter()
            ->unique()
            ->sortByDesc(fn (string $model): int => strlen($model))
            ->filter(fn (string $model): bool => str_contains($normalizedText, $this->normalize($model)))
            ->values()
            ->all();
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
                'topic' => 'PRICE_BY_AREA',
                'label' => 'Bao gia lan banh',
                'question' => 'Anh/Chị cần em báo giá lăn bánh mẫu xe gì ạ?',
                'search_prefix' => 'giá xe báo giá lăn bánh',
            ],
            'PROMOTIONS' => [
                'topic' => 'PROMOTIONS',
                'label' => 'Uu dai hien hanh',
                'question' => 'Anh/Chị quan tâm mẫu xe nào để em kiểm tra chương trình ưu đãi hiện hành ạ?',
                'search_prefix' => 'khuyến mãi ưu đãi chương trình',
            ],
            'INSTALLMENT_LOAN' => [
                'topic' => 'INSTALLMENT_LOAN',
                'label' => 'Vay tra gop',
                'question' => 'Anh/Chị muốn tư vấn trả góp mẫu xe nào và dự kiến trả trước khoảng bao nhiêu ạ?',
                'search_prefix' => 'vay trả góp giá xe',
            ],
            'VEHICLE_AVAILABILITY' => [
                'topic' => 'VEHICLE_AVAILABILITY',
                'label' => 'Tinh trang xe',
                'question' => 'Anh/Chị muốn kiểm tra tình trạng xe, màu xe và thời gian giao xe của mẫu nào ạ?',
                'search_prefix' => 'màu xe tình trạng xe giao xe',
            ],
            'VERSION_CONSULTING' => [
                'topic' => 'VERSION_CONSULTING',
                'label' => 'Tu van phien ban',
                'question' => 'Anh/Chị đang quan tâm mẫu xe nào và nhu cầu sử dụng chính là gì ạ?',
                'search_prefix' => 'tư vấn phiên bản mẫu xe grade',
            ],
        ][$payload] ?? null;
    }

    public function flowFromText(string $text): ?array
    {
        $normalized = $this->normalize($text);

        $map = [
            'bao gia lan banh' => 'PRICE_BY_AREA',
            'uu dai hien hanh' => 'PROMOTIONS',
            'vay tra gop' => 'INSTALLMENT_LOAN',
            'tinh trang xe' => 'VEHICLE_AVAILABILITY',
            'chon phien ban' => 'VERSION_CONSULTING',
        ];

        foreach ($map as $needle => $payload) {
            if (str_contains($normalized, $needle)) {
                return $this->flow($payload);
            }
        }

        return null;
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

    private function installmentDocuments(): array
    {
        $payload = $this->json('ctrinh_tragop.txt');
        $program = $this->value($payload, 'ten_chuong_trinh');
        $period = $this->value($payload, 'thoi_gian_ap_dung');

        return collect((array) data_get($payload, 'cac_goi_uu_dai', []))
            ->flatMap(function (array $row) use ($program, $period): array {
                $package = $this->value($row, 'ten_goi');
                $product = $this->value($row, 'san_pham');
                $phaseOne = $this->value((array) data_get($row, 'lai_suat', []), 'giai_doan_1');
                $phaseTwo = $this->value((array) data_get($row, 'lai_suat', []), 'giai_doan_2');
                $customer = $this->value((array) data_get($row, 'dieu_kien', []), 'doi_tuong');
                $minMonths = (string) (
                    data_get($row, 'dieu_kien.thoi_gian_vay_toi_thieu_thang')
                    ?: data_get($row, 'dieu_kien.thoi_gian_vay_ap_dung_thang')
                    ?: ''
                );

                return collect((array) data_get($row, 'mau_xe_ap_dung', []))
                    ->map(function (string $model) use ($row, $program, $period, $package, $product, $phaseOne, $phaseTwo, $customer, $minMonths): array {
                        $text = trim(implode(' ', array_filter([
                            "Chuong trinh tra gop Toyota Kien Giang {$program}.",
                            $period,
                            "Mau xe ap dung: {$model}.",
                            "Goi uu dai: {$package}.",
                            "San pham vay: {$product}.",
                            $phaseOne !== '' ? "Lai suat giai doan 1: {$phaseOne}." : '',
                            $phaseTwo !== '' ? "Lai suat giai doan 2: {$phaseTwo}." : '',
                            $customer !== '' ? "Doi tuong/dieu kien: {$customer}." : '',
                            $minMonths !== '' ? "Thoi gian vay: {$minMonths} thang." : '',
                        ])));

                        return [
                            'id' => 'installment:'.md5(json_encode($row).$model),
                            'source' => 'ctrinh_tragop',
                            'metadata' => [
                                'program' => $program,
                                'period' => $period,
                                'model' => $model,
                                'package' => $package,
                                'product' => $product,
                                'phase_one' => $phaseOne,
                                'phase_two' => $phaseTwo,
                                'customer' => $customer,
                                'months' => $minMonths,
                            ],
                            'text' => $text,
                        ];
                    })
                    ->all();
            })
            ->filter(fn (array $doc): bool => $doc['text'] !== '')
            ->values()
            ->all();
    }

    private function json(string $filename): array
    {
        $path = base_path('Modules/Chatbot/Data/'.$filename);

        if (! is_file($path)) {
            $path = base_path($filename);
        }

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

    private function normalize(string $value): string
    {
        return str((string) $value)->lower()->ascii()->squish()->toString();
    }
}
