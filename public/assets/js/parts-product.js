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
      button.textContent = isAdded ? button.dataset.addedLabel : button.dataset.defaultLabel;
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

  document.querySelectorAll('[data-product-image]').forEach(button => button.addEventListener('click', () => {
    const main = document.querySelector('[data-product-main-image]');
    if (main) main.src = button.dataset.productImage;
    document.querySelectorAll('[data-product-image]').forEach(item => item.classList.toggle('active', item === button));
  }));

  document.querySelectorAll('[data-recommendations-carousel]').forEach(carousel => {
    const track = carousel.querySelector('[data-recommendations-track]');
    if (!track) return;

    const scroll = direction => track.scrollBy({
      left: direction * Math.max(280, track.clientWidth * 0.85),
      behavior: 'smooth'
    });
    carousel.querySelector('[data-carousel-prev]')?.addEventListener('click', () => scroll(-1));
    carousel.querySelector('[data-carousel-next]')?.addEventListener('click', () => scroll(1));
  });
});
