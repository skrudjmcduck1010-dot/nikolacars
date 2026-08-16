document.addEventListener('DOMContentLoaded', () => {
  const dataElement = document.getElementById('productData');
  if (!dataElement) return;

  const product = JSON.parse(dataElement.textContent);
  const cartKey = 'nikolacars-parts-cart-v1';
  const addButton = document.querySelector('[data-product-add]');
  const addButtonLabel = addButton?.textContent.trim() || '';
  const recommendationProducts = [
    ...(product.similar_products || []),
    ...(product.subcategory_products || [])
  ];
  const cartProduct = { ...product };
  delete cartProduct.similar_products;
  delete cartProduct.subcategory_products;
  const readCart = () => JSON.parse(localStorage.getItem(cartKey) || '[]').filter(item => item && Number.isInteger(item.id));
  const renderCount = cart => {
    const count = cart.reduce((sum, item) => sum + Number(item.quantity || 0), 0);
    document.querySelectorAll('[data-cart-count]').forEach(element => { element.textContent = count; });
  };
  const renderAddButton = cart => {
    if (!addButton) return;

    const isAdded = cart.some(item => item.id === product.id);
    addButton.textContent = isAdded ? addButton.dataset.addedLabel : addButtonLabel;
    addButton.classList.toggle('added', isAdded);
    addButton.setAttribute('aria-pressed', String(isAdded));
  };
  const renderRecommendationButtons = cart => {
    document.querySelectorAll('[data-recommendation-add]').forEach(button => {
      const isAdded = cart.some(item => item.id === Number(button.dataset.recommendationAdd));
      const label = isAdded ? button.dataset.addedLabel : button.dataset.defaultLabel;
      button.setAttribute('aria-label', label);
      button.title = label;
      button.classList.toggle('added', isAdded);
      button.setAttribute('aria-pressed', String(isAdded));
    });
  };

  let cart = readCart();
  renderCount(cart);
  renderAddButton(cart);
  renderRecommendationButtons(cart);

  addButton?.addEventListener('click', () => {
    if (!cart.some(item => item.id === product.id)) {
      cart.push({ ...cartProduct, available: product.quantity, quantity: 1 });
      localStorage.setItem(cartKey, JSON.stringify(cart));
    }
    renderCount(cart);
    renderAddButton(cart);
  });

  document.querySelectorAll('[data-recommendation-add]').forEach(button => button.addEventListener('click', () => {
    const recommendation = recommendationProducts.find(item => item.id === Number(button.dataset.recommendationAdd));
    if (recommendation && !cart.some(item => item.id === recommendation.id)) {
      cart.push({ ...recommendation, available: recommendation.quantity, quantity: 1 });
      localStorage.setItem(cartKey, JSON.stringify(cart));
    }
    renderCount(cart);
    renderAddButton(cart);
    renderRecommendationButtons(cart);
  }));

  document.querySelector('[data-open-cart]')?.addEventListener('click', () => {
    location.href = `${window.productPageConfig.catalogUrl}?cart=open`;
  });
  document.querySelector('[data-scroll-catalog]')?.addEventListener('click', () => {
    location.href = window.productPageConfig.catalogUrl;
  });

  const galleryImages = (product.images || []).filter(Boolean);
  if (!galleryImages.length && product.image_url) galleryImages.push(product.image_url);
  const mainImage = document.querySelector('[data-product-main-image]');
  const galleryOpen = document.querySelector('[data-gallery-open]');
  const lightbox = document.querySelector('[data-product-lightbox]');
  const lightboxImage = lightbox?.querySelector('[data-lightbox-image]');
  const lightboxCounter = lightbox?.querySelector('[data-lightbox-counter]');
  let currentImageIndex = 0;
  let previousFocus = null;
  let pointerStartX = null;

  const selectGalleryImage = index => {
    if (!galleryImages.length) return;
    currentImageIndex = (index + galleryImages.length) % galleryImages.length;
    if (mainImage) mainImage.src = galleryImages[currentImageIndex];
    document.querySelectorAll('[data-product-image]').forEach(button => {
      button.classList.toggle('active', Number(button.dataset.productImageIndex) === currentImageIndex);
    });
  };
  const renderLightboxImage = () => {
    if (!lightboxImage || !galleryImages.length) return;
    lightboxImage.src = galleryImages[currentImageIndex];
    if (lightboxCounter) lightboxCounter.textContent = `${currentImageIndex + 1} / ${galleryImages.length}`;
  };
  const openLightbox = () => {
    if (!lightbox || !galleryImages.length) return;
    previousFocus = document.activeElement;
    renderLightboxImage();
    lightbox.hidden = false;
    lightbox.setAttribute('aria-hidden', 'false');
    document.body.classList.add('product-lightbox-open');
    lightbox.querySelector('[data-lightbox-close]:not(.product-lightbox-backdrop)')?.focus();
  };
  const closeLightbox = () => {
    if (!lightbox || lightbox.hidden) return;
    lightbox.hidden = true;
    lightbox.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('product-lightbox-open');
    previousFocus?.focus?.();
  };
  const moveLightbox = direction => {
    selectGalleryImage(currentImageIndex + direction);
    renderLightboxImage();
  };

  document.querySelectorAll('[data-product-image]').forEach(button => button.addEventListener('click', () => {
    selectGalleryImage(Number(button.dataset.productImageIndex));
  }));
  galleryOpen?.addEventListener('click', openLightbox);
  lightbox?.querySelectorAll('[data-lightbox-close]').forEach(button => button.addEventListener('click', closeLightbox));
  lightbox?.querySelector('[data-lightbox-prev]')?.addEventListener('click', () => moveLightbox(-1));
  lightbox?.querySelector('[data-lightbox-next]')?.addEventListener('click', () => moveLightbox(1));
  lightbox?.querySelector('[data-lightbox-stage]')?.addEventListener('pointerdown', event => {
    pointerStartX = event.clientX;
  });
  lightbox?.querySelector('[data-lightbox-stage]')?.addEventListener('pointerup', event => {
    if (pointerStartX === null) return;
    const distance = event.clientX - pointerStartX;
    pointerStartX = null;
    if (Math.abs(distance) >= 50 && galleryImages.length > 1) moveLightbox(distance < 0 ? 1 : -1);
  });
  document.addEventListener('keydown', event => {
    if (!lightbox || lightbox.hidden) return;
    if (event.key === 'Escape') closeLightbox();
    if (event.key === 'ArrowLeft' && galleryImages.length > 1) moveLightbox(-1);
    if (event.key === 'ArrowRight' && galleryImages.length > 1) moveLightbox(1);
  });

  document.querySelectorAll('[data-recommendations-carousel]').forEach(carousel => {
    const track = carousel.querySelector('[data-recommendations-track]');
    if (!track) return;

    const scroll = direction => {
      const maxScrollLeft = Math.max(0, track.scrollWidth - track.clientWidth);
      const edgeTolerance = 2;

      if (direction > 0 && track.scrollLeft >= maxScrollLeft - edgeTolerance) {
        track.scrollTo({ left: 0, behavior: 'smooth' });
        return;
      }

      if (direction < 0 && track.scrollLeft <= edgeTolerance) {
        track.scrollTo({ left: maxScrollLeft, behavior: 'smooth' });
        return;
      }

      track.scrollBy({
        left: direction * Math.max(280, track.clientWidth * 0.85),
        behavior: 'smooth'
      });
    };
    carousel.querySelector('[data-carousel-prev]')?.addEventListener('click', () => scroll(-1));
    carousel.querySelector('[data-carousel-next]')?.addEventListener('click', () => scroll(1));
  });
});
