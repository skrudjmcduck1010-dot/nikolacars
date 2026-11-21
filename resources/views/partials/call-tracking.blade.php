@php
  $callTrackingHost = strtolower(request()->getHost());
  $callTrackingUsesDisassemblyNumbers = in_array($callTrackingHost, [
    'nikolacars.com.ua',
    'www.nikolacars.com.ua',
  ], true) || request()->is(
    'parts',
    'parts/*',
    'ru/parts',
    'ru/parts/*',
    'ua/parts',
    'ua/parts/*',
  );
@endphp
<script>
(function () {
  const usesDisassemblyNumbers = {{ $callTrackingUsesDisassemblyNumbers ? 'true' : 'false' }};
  if (!usesDisassemblyNumbers) return;

  const partsPhoneHref = 'tel:+380990698380';
  const partsPhoneLabel = '+38 (099) 069 83 80';

  document.querySelectorAll('a[href^="tel:"]').forEach(function (link) {
    const href = link.getAttribute('href');
    if (href === 'tel:+380634730819') {
      link.classList.add('binct-phone-number-2');
      return;
    }

    if (href !== 'tel:+380975120255' && href !== partsPhoneHref) return;

    link.setAttribute('href', partsPhoneHref);
    link.classList.add('binct-phone-number-1');

    const walker = document.createTreeWalker(link, NodeFilter.SHOW_TEXT);
    let node;
    while ((node = walker.nextNode())) {
      if (/\+?38\s*\(?0(?:97|99)\)?[\d\s-]*/.test(node.nodeValue)) {
        node.nodeValue = node.nodeValue.replace(
          /\+?38\s*\(?0(?:97|99)\)?[\d\s-]*/g,
          partsPhoneLabel
        );
      }
    }
  });

})();
</script>
<script
  async
  src="https://widgets.binotel.com/calltracking/widgets/zzn1oxw78e23q8qja7yn.js">
</script>
