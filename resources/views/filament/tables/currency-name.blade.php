@php
    $url = $record instanceof \App\Models\Currency ? $record->imageUrl() : null;
    $name = $record instanceof \App\Models\Currency ? $record->name : '';
@endphp

<span style="display:inline-flex;align-items:center;gap:0.5rem;">
    @if (filled($url))
        <img
            src="{{ $url }}"
            alt=""
            width="28"
            height="20"
            style="width:28px;height:20px;object-fit:cover;border-radius:2px;box-shadow:0 0 0 1px rgba(0,0,0,.12);"
        />
    @endif
    <span>{{ $name }}</span>
</span>
