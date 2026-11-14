<div class="mobile-menu-phones">
  @foreach(['+380501753084' => '+38 (050) 175 30 84', '+380637745764' => '+38 (063) 774 57 64'] as $phoneHref => $phoneLabel)
    <a href="tel:{{ $phoneHref }}" data-menu-phone="{{ $loop->index }}">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .3 1.9.7 2.8a2 2 0 0 1-.5 2.1L8 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.4 1.8.6 2.8.7a2 2 0 0 1 1.8 2.1z"/></svg>
      <span data-menu-phone-label>{{ $phoneLabel }}</span>
    </a>
  @endforeach
</div>
<style>
.mobile-menu-phones{display:none}
@media(max-width:900px){
  .mobile-menu-phones{display:grid;gap:8px;margin-top:12px;padding-top:12px;border-top:1px solid rgba(255,255,255,.12)}
  .mobile-menu-phones a{display:flex;align-items:center;gap:12px;min-height:48px;padding:10px 16px;border-radius:12px;background:rgba(37,107,54,.22);color:#fff;font-size:17px;text-decoration:none}
  .mobile-menu-phones svg{flex-shrink:0;width:22px;height:22px;fill:none;stroke:#69cf83;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
  .mobile-menu-phones a:focus-visible{outline:2px solid #69cf83;outline-offset:2px}
}
</style>
