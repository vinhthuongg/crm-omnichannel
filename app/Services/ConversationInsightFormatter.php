<?php

namespace App\Services;

class ConversationInsightFormatter
{
    /** Chuyển insight đã trích xuất thành summary nhu cầu, xe, số điện thoại và tư vấn viên. */
    public function format(string $customer, ?string $vehicle, array $intents, array $advisors,
        ?string $storedPhone, ?string $latestPhone, ?string $phone): string
    {
        return trim($customer.' '.$this->needs($vehicle, $intents).'. '.$this->advisors($advisors).' va '.
            $this->phone($storedPhone, $latestPhone, $phone).'.');
    }

    /** Định dạng danh sách nhu cầu được phát hiện trong hội thoại. */
    private function needs(?string $vehicle, array $intents): string
    {
        if ($intents === [] && ! $vehicle) return 'dang trao doi voi Toyota Kien Giang';
        $parts = [];
        if (in_array('mua xe', $intents, true) || $vehicle) $parts[] = 'muon tham khao'.($vehicle ? ' Toyota '.$vehicle : ' xe Toyota');
        $labels = ['bao gia' => 'can bao gia', 'tra gop' => 'quan tam tra gop', 'lai thu' => 'muon lai thu',
            'dat lich' => 'muon dat lich', 'dich vu' => 'can ho tro dich vu', 'uu dai' => 'hoi ve uu dai'];
        foreach ($labels as $intent => $label) if (in_array($intent, $intents, true)) $parts[] = $label;
        return implode(' va ', array_values(array_unique($parts)));
    }

    /** Trích xuất hoặc định dạng danh sách nhân viên tư vấn liên quan. */
    private function advisors(array $advisors): string
    {
        if ($advisors === []) return 'chua co nhan vien tu van';
        if (count($advisors) === 1) return $advisors[0].' da tu van';
        $last = array_pop($advisors);
        return implode(', ', $advisors).' va '.$last.' da tu van';
    }

    /** Trích xuất và chuẩn hóa số điện thoại từ nội dung đầu vào. */
    private function phone(?string $stored, ?string $latest, ?string $phone): string
    {
        if ($stored && $latest && $stored !== $latest) return 'dang luu so '.$stored.', khach vua gui them so '.$latest;
        return $phone ? 'da co so dien thoai '.$phone : 'chua lay duoc so dien thoai';
    }
}
