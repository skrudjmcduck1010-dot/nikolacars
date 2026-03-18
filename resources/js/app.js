import './bootstrap';
document.addEventListener('DOMContentLoaded', () => {

  const root = document.getElementById('reviewsSlider');
  if (!root) return;

  const track = root.querySelector('.reviews-track');
  const slides = Array.from(root.querySelectorAll('.review'));
  const dotsWrap = root.querySelector('.reviews-dots');
  const btnPrev = root.querySelector('.reviews-arrow.prev');
  const btnNext = root.querySelector('.reviews-arrow.next');

  let index = 0;

  // dots
  const dots = slides.map((_, i) => {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'reviews-dot' + (i === 0 ? ' is-active' : '');
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

  btnPrev?.addEventListener('click', () => go(index - 1));
  btnNext?.addEventListener('click', () => go(index + 1));

  update();
});
