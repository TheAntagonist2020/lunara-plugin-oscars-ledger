const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict');
const {chromium}=require('../../lunara-theme-home-carousels/node_modules/playwright-core');
const root=path.resolve(__dirname,'..');
const captures=path.resolve(process.argv[2]||path.join(root,'../_carousel-artifacts/dossier-mobile-2.7.84'));
// The .html captures retain the public PHP response and complete production CSS
// bundle. Candidate blocks are rebuilt from source, never trusted from a prior run.
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.LUNARA_BROWSER_EXECUTABLE||'C:/Program Files/Google/Chrome/Application/chrome.exe'});let checks=0;
 const check=(v,m)=>{checks++;assert.ok(v,m)};
 try{for(const width of [320,390,768,1440])for(const kind of ['ceremony','category','title','name']){
  let html=fs.readFileSync(path.join(captures,kind+'.html'),'utf8').replace(/<style data-candidate=[^>]*>[\s\S]*?<\/style>/g,'');
  let entity=fs.readFileSync(path.join(root,'assets/css/academy-awards-table.css'),'utf8').split('/* Mobile profile hierarchy')[1];
  let hub=fs.readFileSync(path.join(root,'assets/css/hub-polish.css'),'utf8');
  if(process.env.AAT_DOSSIER_MUTATION==='actions')hub=hub.replace(/min-height: ?44px ?!important/g,'min-height:28px!important');
  if(process.env.AAT_DOSSIER_MUTATION==='metrics')hub=hub.replace('grid-template-columns: repeat(2, minmax(0, 1fr)) !important','grid-template-columns: minmax(0, 1fr) !important');
  if(process.env.AAT_DOSSIER_MUTATION==='title')entity=entity.replace('max-width:100%!important;font-size:clamp(2rem,7vw,3rem)!important','max-width:10ch!important;font-size:clamp(2rem,12vw,3rem)!important');
  // These assets load in the head; body template styles remain after them.
  html=html.replace('</head>','<style>/* Mobile profile hierarchy'+entity+'\n'+hub+'</style></head>');
  const page=await browser.newPage({viewport:{width,height:900},reducedMotion:'reduce',javaScriptEnabled:false});
  await page.setContent(html,{waitUntil:'domcontentloaded'});
  const result=await page.evaluate(()=>{
   const title=document.querySelector('h1'),poster=document.querySelector('.aat-entity-poster-wrap'),stats=document.querySelector('.aat-profile-command-band');
   const summary=document.querySelector('.aat-dossier-command-band,.aat-ceremony-command-band');
   const rect=e=>({w:e.getBoundingClientRect().width,h:e.getBoundingClientRect().height,font:parseFloat(getComputedStyle(e).fontSize)});
   const actions=[...document.querySelectorAll('.aat-category-history-actions a,.aat-entity-actions a,.aat-ceremony-dossier-actions a')];
   return {summary:summary&&{columns:getComputedStyle(summary).gridTemplateColumns.split(' ').length,width:summary.clientWidth,cards:[...summary.children].map(e=>({...rect(e),overflow:e.scrollWidth>e.clientWidth+1,text:e.textContent.trim()}))},overflow:document.documentElement.scrollWidth,title:rect(title),poster:poster&&rect(poster),columns:stats&&getComputedStyle(stats).gridTemplateColumns.split(' ').length,actions:actions.map(rect),image:poster&&poster.querySelector('img')&&{fit:getComputedStyle(poster.querySelector('img')).objectFit,focus:getComputedStyle(poster.querySelector('img')).objectPosition}};
  });
  check(result.overflow<=width+1,`${kind}/${width}: horizontal overflow`);
  check(result.title.w<=width,`${kind}/${width}: title fits`);
  if(width<=768){
   check(result.title.font<=48,`${kind}/${width}: readable title scale`);
   for(const action of result.actions){check(action.h>=43.9,`${kind}/${width}: 44px action`);check(action.font>=13.9,`${kind}/${width}: readable action`)}
   if(result.summary){check(result.summary.columns===2,`${kind}/${width}: two-column dossier metrics`);for(const card of result.summary.cards)check(!card.overflow,`${kind}/${width}: full metric value`);if(kind==='category')check(result.summary.cards[0].w>=result.summary.width-1,`${kind}/${width}: latest artwork stays full width`)}
   if(result.columns)check(result.columns===2,`${kind}/${width}: compact two-column stats`);
  }
  if(width<=540&&result.poster){check(result.poster.w<=180.1&&result.poster.w>=140,`${kind}/${width}: intentional portrait size`);check(result.title.h<=110,`${kind}/${width}: full-width title hierarchy`);check(['cover','contain'].includes(result.image.fit),`${kind}/${width}: saved artwork fit remains`)}
  await page.screenshot({path:path.join(captures,`${kind}-${width}.png`),fullPage:false});
  if(result.poster){for(const fit of ['contain','cover']){await page.evaluate(fit=>{const style=document.createElement("style");style.textContent=`body .aat-profile-file{--lunara-oscars-profile-media-fit:${fit}!important;--lunara-oscars-image-focus:25% 70%!important}`;document.head.appendChild(style)},fit);const saved=await page.locator('.aat-entity-poster-wrap img').first().evaluate(e=>({fit:getComputedStyle(e).objectFit,focus:getComputedStyle(e).objectPosition}));check(saved.fit===fit,`${kind}/${width}: saved ${fit} fit`);check(saved.focus==='25% 70%',`${kind}/${width}: saved focal point`)}}
  await page.close();
 }console.log(`Dossier mobile browser passed: ${checks} checks (4 routes x 4 widths; reduced motion, no JavaScript).`)}finally{await browser.close()}
})().catch(e=>{console.error(e);process.exit(1)});
