document.addEventListener('DOMContentLoaded', () => {
  const root = document.getElementById('reviewsSlider');
  if (!root) return;

  const track = root.querySelector('.reviews-track');
  const slides = Array.from(root.querySelectorAll('.review'));
  const dotsWrap = root.querySelector('.reviews-dots');
  const btnPrev = root.querySelector('.reviews-arrow.prev');
  const btnNext = root.querySelector('.reviews-arrow.next');

  if (!track || slides.length === 0 || !dotsWrap) return;

  let index = 0;

  // create dots
  const dots = slides.map((_, i) => {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'reviews-dot' + (i === 0 ? ' is-active' : '');
    b.setAttribute('aria-label', 'Slide ' + (i + 1));
    b.addEventListener('click', () => go(i));
    dotsWrap.appendChild(b);
    return b;
  });

  function update(){
    track.style.transform = `translateX(-${index * 100}%)`;
    dots.forEach((d, i) => d.classList.toggle('is-active', i === index));
  }

  function go(i){
    index = (i + slides.length) % slides.length;
    update();
  }

  btnPrev && btnPrev.addEventListener('click', () => go(index - 1));
  btnNext && btnNext.addEventListener('click', () => go(index + 1));

  // swipe (optional)
  let x0 = null;
  root.addEventListener('pointerdown', (e) => { x0 = e.clientX; });
  root.addEventListener('pointerup', (e) => {
    if (x0 === null) return;
    const dx = e.clientX - x0;
    x0 = null;
    if (Math.abs(dx) < 40) return;
    dx < 0 ? go(index + 1) : go(index - 1);
  });

  update();
});
