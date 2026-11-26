<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0.6rem;margin-bottom:0;">
    <div style="border-radius:0.8rem;padding:0.55rem 0.9rem;background:linear-gradient(135deg,#0f766e,#14b8a6);color:#fff;box-shadow:0 6px 16px rgba(15,118,110,.22);">
        <div style="font-size:.75rem;opacity:.9;margin-bottom:.15rem;">سعر العملة مقابل الدينار</div>
        <div style="font-size:1.35rem;line-height:1.2;font-weight:700;letter-spacing:.02em;direction:ltr;text-align:right;">{{ $rate }}</div>
    </div>
    <div style="border-radius:0.8rem;padding:0.55rem 0.9rem;background:linear-gradient(135deg,#1d4ed8,#3b82f6);color:#fff;box-shadow:0 6px 16px rgba(29,78,216,.22);">
        <div style="font-size:.75rem;opacity:.9;margin-bottom:.15rem;">القيمة الإجمالية بالدينار</div>
        <div style="font-size:1.35rem;line-height:1.2;font-weight:700;letter-spacing:.02em;direction:ltr;text-align:right;">{{ $total }}</div>
    </div>
</div>
