const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('C:/Users/user/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
(async () => {
  const output = path.resolve(process.argv[2] || '.codex-tmp/tree-e2e/browser'); fs.mkdirSync(output,{recursive:true});
  const modelSlugs=Object.keys(JSON.parse(fs.readFileSync(path.join(__dirname,'../fixtures/catalog-tree-rows.json'),'utf8'))._fixture.models).sort();
  const browser = await chromium.launch({channel:'msedge',headless:true}); const results=[];
  try {
    for (const locale of ['uk','ru']) for (const width of [320,390,1440]) {
      const base = (locale==='ru'?'/ru':'')+'/parts/';
      const context=await browser.newContext({viewport:{width,height:950}}); const page=await context.newPage(); const errors=[];
      page.on('pageerror',e=>errors.push(e.message));
      await page.route('**/*',route=>{
        const request=route.request();
        if(request.method()!=='GET'||new URL(request.url()).origin!=='http://127.0.0.1:4336')return route.abort();
        return route.continue();
      });
      const geometry=async()=>{
        if(await page.locator('[data-model-intro], [data-current-context]').count())throw new Error('Generic catalog guidance must not render');
        const data=await page.locator('.parts-sidebar').evaluate(sidebar=>{
          const box=sidebar.getBoundingClientRect();
          return {sidebarRight:box.right,viewport:innerWidth,failures:[...sidebar.querySelectorAll('.parts-category-node')].flatMap(a=>{
            const ab=a.getBoundingClientRect(),label=a.querySelector('.parts-category-label'),lb=label.getBoundingClientRect(),count=a.querySelector('b').getBoundingClientRect();
            return ab.right>box.right+1 || lb.right>ab.right+1 || lb.right>count.left+1 || lb.bottom>ab.bottom+1 ? [{href:a.pathname,anchorRight:ab.right,labelRight:lb.right,countLeft:count.left,whiteSpace:getComputedStyle(label).whiteSpace}] : [];
          })};
        });
        if(data.sidebarRight>data.viewport+1||data.failures.length)throw new Error(JSON.stringify({locale,width,...data}));
        if(await page.locator('.parts-sidebar [aria-current="page"]').count()!==1)throw new Error('Exactly one current node');
      };
      await page.goto('http://127.0.0.1:4336'+base+'elektrika/?q=missing&sort=name',{waitUntil:'networkidle'});
      await geometry();
      await Promise.all([page.waitForURL('**'+base+'elektrika/elektroprovodka/'),page.locator('.parts-sidebar a[href="'+base+'elektrika/elektroprovodka/"]').click()]);
      await page.waitForLoadState('networkidle'); await geometry();
      if(new URL(page.url()).search)throw new Error('Navigation must clear search/sort/page');
      if(await page.locator('.parts-sidebar .is-ancestor').count()!==1)throw new Error('Root ancestor missing');
      if(await page.locator('.parts-sidebar [style="--category-depth:2"]').count()<1)throw new Error('Next level missing');
      await page.screenshot({path:path.join(output,`${locale}-${width}-page.png`),fullPage:false});
      await page.locator('.parts-sidebar').screenshot({path:path.join(output,`${locale}-${width}-sidebar.png`)});
      const before=await page.locator('[data-categories]').innerText();
      await page.locator('[data-sort]').selectOption('price_asc');
      await page.waitForFunction(()=>!document.querySelector('[data-products]').classList.contains('loading'));
      if(await page.locator('[data-categories]').innerText()!==before)throw new Error('AJAX changed tree/counts');
      await geometry();
      const leaf=page.locator('.parts-sidebar [style="--category-depth:2"]').first();const leafUrl=await leaf.getAttribute('href');
      await Promise.all([page.waitForURL('**'+leafUrl),leaf.click()]);await page.waitForLoadState('networkidle');await geometry();
      if((await page.locator('.parts-sidebar [aria-current="page"] .parts-category-chevron').textContent()).trim()!=='')throw new Error('Leaf must have no expanding arrow');
      await page.locator('[data-all-parts]').click();await page.waitForURL('**'+base);await geometry();
      if(await page.locator('[data-preview-fixture]').count()!==1)throw new Error('Fixture notice missing');
      for(const slug of modelSlugs) if(await page.locator(`[data-models] a[href="${base+slug}/"]`).count()!==1)throw new Error('Root model missing: '+slug);
      await Promise.all([page.waitForURL('**'+base+modelSlugs[0]+'/'),page.locator(`[data-models] a[href="${base+modelSlugs[0]}/"]`).click()]);
      await geometry();
      await Promise.all([page.waitForURL('**'+base),page.locator(`[data-models] a[href="${base}"]`).click()]);
      await Promise.all([page.waitForURL('**'+base+'elektrika/'),page.locator(`.parts-sidebar a[href="${base}elektrika/"]`).click()]);
      for(const slug of modelSlugs) if(await page.locator(`[data-models] a[href="${base+slug}/elektrika/"]`).count()!==1)throw new Error('Electrical model missing: '+slug);
      await Promise.all([page.waitForURL('**'+base+'elektrika/elektroprovodka/'),page.locator(`.parts-sidebar a[href="${base}elektrika/elektroprovodka/"]`).click()]);
      for(const slug of modelSlugs){
        const target=base+slug+'/elektrika/elektroprovodka/';
        await Promise.all([page.waitForURL('**'+target),page.locator(`[data-models] a[href="${target}"]`).click()]);
        await page.waitForLoadState('networkidle');await geometry();
        const links=await page.locator('[data-categories] a').evaluateAll(nodes=>nodes.map(n=>n.getAttribute('href')));
        if(links.some(href=>!href.startsWith(base+slug+'/')))throw new Error('Model context lost: '+slug);
        if(await page.locator('.parts-sidebar [style="--category-depth:2"]').count()<1)throw new Error('Shared branch missing: '+slug);
        if(await page.locator(`[data-models] a.active[href="${target}"]`).count()!==1)throw new Error('Model active state lost: '+slug);
      }
      if(errors.length)throw new Error(errors.join('\n'));
      results.push({locale,width,status:'PASS',modelTransitions:modelSlugs,checks:['label/anchor/sidebar bounds','single current','real child/leaf/all navigation','filter clearing','ancestors','leaf arrow','AJAX counts','all six model groups at root and Electrical','real model transitions in shared branch','preview disclosure','model context','no JS errors']});
      await context.close();
    }
    fs.writeFileSync(path.join(output,'result.json'),JSON.stringify({status:'PASS',cases:results.length,results},null,2));
    console.log(`PASS ${results.length} locale/viewport journeys; screenshots ${output}`);
  } finally { await browser.close(); }
})().catch(error=>{console.error(error.stack);process.exit(1);});
