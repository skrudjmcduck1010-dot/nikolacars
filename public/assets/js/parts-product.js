document.addEventListener('DOMContentLoaded', () => {
  const dataElement = document.getElementById('productData');
  if (!dataElement) return;

  const product = JSON.parse(dataElement.textContent);
  const cartKey = 'nikolacars-parts-cart-v1';
  const readCart = () => JSON.parse(localStorage.getItem(cartKey) || '[]').filter(item => item && Number.isInteger(item.id));
  const renderCount = cart => {
    const count = cart.reduce((sum, item) => sum + Number(item.quantity || 0), 0);
    document.querySelectorAll('[data-cart-count]').forEach(element => { element.textContent = count; });
  };

  let cart = readCart();
  renderCount(cart);

  document.querySelector('[data-product-add]')?.addEventListener('click', () => {
    if (!cart.some(item => item.id === product.id)) {
      cart.push({ ...product, available: product.quantity, quantity: 1 });
      localStorage.setItem(cartKey, JSON.stringify(cart));
    }
    location.href = `${window.productPageConfig.catalogUrl}?cart=open`;
  });

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
});
