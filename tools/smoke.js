const {JSDOM}=require('jsdom');const fs=require('fs');
const S=require('path').resolve(__dirname,'../docs/artifact-version.html');
const html='<!doctype html><html><body>'+fs.readFileSync(S,'utf8').replace(/<link[^>]*>/g,'')+'</body></html>';
const errs=[];
const dom=new JSDOM(html,{runScripts:'dangerously',pretendToBeVisual:true,url:'http://localhost/'});
const w=dom.window;w.addEventListener('error',e=>errs.push(e.message));
let now=0;const rp=w.performance.now.bind(w.performance);
const doc=w.document;const $=s=>doc.querySelector(s);
const click=s=>$(s).dispatchEvent(new w.MouseEvent('click',{bubbles:true}));
const pd=(el)=>el.dispatchEvent(new w.Event('pointerdown',{bubbles:true,cancelable:true}));
const sleep=ms=>new Promise(r=>setTimeout(r,ms));
// speed: patch performance.now to run 10x
const t0=Date.now();const pn=()=>(Date.now()-t0)*10;w.performance.now=pn;const raf=w.requestAnimationFrame.bind(w);w.requestAnimationFrame=cb=>raf(()=>cb(pn()));
(async()=>{
  for(const subj of ['math','rus']){
    doc.querySelector(`[data-group=subject][data-v=${subj}]`).dispatchEvent(new w.MouseEvent('click',{bubbles:true}));
    if(!$('#topics .tchip')) errs.push('no topics');
    click('#btn-start');
    for(let i=0;i<2000&&!$('#screen-result').hidden===false;i++){
      await sleep(30);
      if($('#screen-match').hidden)break;
      if(subj==='rus'){const b=doc.querySelectorAll('#choices .opt');if(b.length)pd(b[Math.floor(Math.random()*b.length)]);}
      else {const k=doc.querySelector(`.key[data-k="${1+Math.floor(Math.random()*9)}"]`);pd(k);}
    }
    for(let i=0;i<2000&&$('#screen-result').hidden;i++)await sleep(50);
    console.log(subj,'result shown:',!$('#screen-result').hidden,$('#res-title').textContent,$('#res-you-pts').textContent,$('#res-topics').textContent.slice(0,60));
    click('#btn-menu');
  }
  click('#btn-progress');console.log('progress rows',doc.querySelectorAll('.prow').length,$('#pnote').textContent);
  click('#btn-progress-back');
  console.log('coach:',$('#coach-text').textContent);
  console.log('errors:',errs);
  process.exit(errs.length?1:0);
})();
