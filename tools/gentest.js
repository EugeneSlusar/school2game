const fs=require('fs');
const html=fs.readFileSync(require('path').resolve(__dirname,'../docs/artifact-version.html'),'utf8');
const m=html.match(/\/\*==GEN-START==\*\/([\s\S]*?)\/\*==GEN-END==\*\//);
const code=m[1]+';return {LEVELS,TOPICS,SUBJECTS,makeQuestion,mulberry32};';
const G=new Function(code)();
let bad=0;const fail=(...a)=>{bad++;if(bad<20)console.log('FAIL',...a)};
for(const lv of Object.keys(G.LEVELS))for(const t of Object.keys(G.TOPICS)){
  const rng=G.mulberry32(7);let prev=null;
  for(let i=0;i<3000;i++){
    const q=G.makeQuestion(rng,lv,t,prev);prev=q;
    if(!q.full||!(q.limit>0))fail('base',lv,t,q);
    if(q.kind==='num'){
      if(!Number.isInteger(q.ans)||q.ans<=0||q.ans>999)fail('ans',lv,t,q);
      const mm=q.full.match(/= (\d+)$/); if(!mm||+mm[1]!==q.ans&&q.form!=='miss')fail('full',lv,t,q);
    } else {
      if(new Set(q.options).size!==q.options.length)fail('dup',lv,t,q);
      if(!(q.ans>=0&&q.ans<q.options.length))fail('idx',lv,t,q);
      if(t==='stress'){const caps=q.options.map(o=>[...o].filter(c=>c!==c.toLowerCase()).length);if(caps.some(c=>c!==1))fail('stresscaps',q);}
      if(t==='vowel'&&!q.prompt.includes('_'))fail('gap',q);
      if(t==='tsya'&&!q.prompt.includes('_'))fail('gap',q);
    }
  }
}
console.log(bad?'FAILED '+bad:'gen OK');
