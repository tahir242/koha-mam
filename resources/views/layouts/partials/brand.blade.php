@php
    $hex = fn ($value, $fallback) => preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $value) ? $value : $fallback;
    $brand = config('dam.branding');
@endphp
<style nonce="{{ Vite::cspNonce() }}">
    :root {
        --brand-primary: {{ $hex($brand['primary'], '#063B5C') }};
        --brand-secondary: {{ $hex($brand['secondary'], '#B00A04') }};
        --brand-accent: {{ $hex($brand['accent'], '#00876C') }};
        --brand-text: {{ $hex($brand['text'], '#2D3748') }};
    }
</style>
