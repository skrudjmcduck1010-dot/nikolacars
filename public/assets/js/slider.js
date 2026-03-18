(() => {
  const root = document.getElementById('heroSlider');
  if (!root) return;

  const track = root.querySelector('.hero-track');
  const slides = Array.from(root.querySelectorAll('.hero-slide'));
  const prev = root.querySelector('.hero-arrow.prev');
  const next = root.querySelector('.hero-arrow.next');
  const dots = Array.from(root.querySelectorAll('.hero-dot'));

  let index = 0;
  let dragging = false;
  let startX = 0;
  let currentX = 0;
  let baseTranslate = 0;

  const setIndex = (i) => {
    index = (i + slides.length) % slides.length;
    track.style.transition = 'transform .45s ease';
    track.style.transform = `translateX(${-index * 100}%)`;
    dots.forEach((d, di) => d.classList.toggle('active', di === index));
  };

  prev.addEventListener('click', () => setIndex(index - 1));
  next.addEventListener('click', () => setIndex(index + 1));
  dots.forEach(d => d.addEventListener('click', () => setIndex(parseInt(d.dataset.dot, 10))));

  // Drag / swipe (mouse + touch)
  const onDown = (x) => {
    dragging = true;
    startX = x;
    currentX = x;
    track.style.transition = 'none';
    baseTranslate = -index * root.clientWidth;
  };

  const onMove = (x) => {
    if (!dragging) return;
    currentX = x;
    const dx = currentX - startX;
    track.style.transform = `translateX(${baseTranslate + dx}px)`;
  };

  const onUp = () => {
    if (!dragging) return;
    dragging = false;
    const dx = currentX - startX;
    const threshold = Math.max(60, root.clientWidth * 0.08);
    if (dx > threshold) setIndex(index - 1);
    else if (dx < -threshold) setIndex(index + 1);
    else setIndex(index);
  };

  // Mouse
  root.addEventListener('mousedown', (e) => onDown(e.clientX));
  window.addEventListener('mousemove', (e) => onMove(e.clientX));
  window.addEventListener('mouseup', onUp);

  // Touch
  root.addEventListener('touchstart', (e) => onDown(e.touches[0].clientX), { passive: true });
  root.addEventListener('touchmove', (e) => onMove(e.touches[0].clientX), { passive: true });
  root.addEventListener('touchend', onUp);

  // Resize fix
  window.addEventListener('resize', () => setIndex(index));

  setIndex(0);
})();
