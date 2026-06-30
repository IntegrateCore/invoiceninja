import{r as ir,g as cr}from"./react-CpWiBZqO.js";var L={};var K;function lr(){return K||(K=1,(function(Y){(()=>{var G={184:(n,i)=>{var c;(function(){var u={}.hasOwnProperty;function s(){for(var o=[],l=0;l<arguments.length;l++){var a=arguments[l];if(a){var m=typeof a;if(m==="string"||m==="number")o.push(a);else if(Array.isArray(a)){if(a.length){var j=s.apply(null,a);j&&o.push(j)}}else if(m==="object"){if(a.toString!==Object.prototype.toString&&!a.toString.toString().includes("[native code]")){o.push(a.toString());continue}for(var w in a)u.call(a,w)&&a[w]&&o.push(w)}}}return o.join(" ")}n.exports?(s.default=s,n.exports=s):(c=(function(){return s}).apply(i,[]))===void 0||(n.exports=c)})()},28:(n,i,c)=>{c.d(i,{Z:()=>a});var u=c(81),s=c.n(u),o=c(645),l=c.n(o)()(s());l.push([n.id,`/* :where() gives the styles specificity 0, which makes them overridable */
:where(.vi__wrapper) {
  position: relative;
  width: min-content;
}

.vi {
  top: 0;
  right: 0;
  bottom: 0;
  left: 0;
  box-sizing: border-box;
  position: absolute;
  color: transparent;
  background: transparent;
  caret-color: transparent;
  outline: none;
  border: 0 none transparent;
}

.vi::-ms-reveal,
.vi::-ms-clear {
  display: none;
}

.vi::selection {
  background: transparent;
}

:where(.vi__container) {
  display: flex;
  gap: 8px;
  height: 50px;
  width: 300px;
}

:where(.vi__character) {
  height: 100%;
  flex-grow: 1;
  flex-basis: 0;
  text-align: center;
  font-size: 36px;
  line-height: 50px;
  color: black;
  background-color: white;
  border: 1px solid black;
  cursor: default;
  user-select: none;
  box-sizing: border-box;
}

:where(.vi__character--inactive) {
  color: dimgray;
  background-color: lightgray;
}

:where(.vi__character--selected) {
  outline: 2px solid cornflowerblue;
  color: cornflowerblue;
}
`,""]);const a=l},645:n=>{n.exports=function(i){var c=[];return c.toString=function(){return this.map((function(u){var s="",o=u[5]!==void 0;return u[4]&&(s+="@supports (".concat(u[4],") {")),u[2]&&(s+="@media ".concat(u[2]," {")),o&&(s+="@layer".concat(u[5].length>0?" ".concat(u[5]):""," {")),s+=i(u),o&&(s+="}"),u[2]&&(s+="}"),u[4]&&(s+="}"),s})).join("")},c.i=function(u,s,o,l,a){typeof u=="string"&&(u=[[null,u,void 0]]);var m={};if(o)for(var j=0;j<this.length;j++){var w=this[j][0];w!=null&&(m[w]=!0)}for(var P=0;P<u.length;P++){var p=[].concat(u[P]);o&&m[p[0]]||(a!==void 0&&(p[5]===void 0||(p[1]="@layer".concat(p[5].length>0?" ".concat(p[5]):""," {").concat(p[1],"}")),p[5]=a),s&&(p[2]&&(p[1]="@media ".concat(p[2]," {").concat(p[1],"}")),p[2]=s),l&&(p[4]?(p[1]="@supports (".concat(p[4],") {").concat(p[1],"}"),p[4]=l):p[4]="".concat(l)),c.push(p))}},c}},81:n=>{n.exports=function(i){return i[1]}},703:(n,i,c)=>{var u=c(414);function s(){}function o(){}o.resetWarningCache=s,n.exports=function(){function l(j,w,P,p,D,I){if(I!==u){var k=new Error("Calling PropTypes validators directly is not supported by the `prop-types` package. Use PropTypes.checkPropTypes() to call them. Read more at http://fb.me/use-check-prop-types");throw k.name="Invariant Violation",k}}function a(){return l}l.isRequired=l;var m={array:l,bigint:l,bool:l,func:l,number:l,object:l,string:l,symbol:l,any:l,arrayOf:a,element:l,elementType:l,instanceOf:a,node:l,objectOf:a,oneOf:a,oneOfType:a,shape:a,exact:a,checkPropTypes:o,resetWarningCache:s};return m.PropTypes=m,m}},697:(n,i,c)=>{n.exports=c(703)()},414:n=>{n.exports="SECRET_DO_NOT_PASS_THIS_OR_YOU_WILL_BE_FIRED"}},z={};function h(n){var i=z[n];if(i!==void 0)return i.exports;var c=z[n]={id:n,exports:{}};return G[n](c,c.exports,h),c.exports}h.n=n=>{var i=n&&n.__esModule?()=>n.default:()=>n;return h.d(i,{a:i}),i},h.d=(n,i)=>{for(var c in i)h.o(i,c)&&!h.o(n,c)&&Object.defineProperty(n,c,{enumerable:!0,get:i[c]})},h.o=(n,i)=>Object.prototype.hasOwnProperty.call(n,i),h.r=n=>{typeof Symbol<"u"&&Symbol.toStringTag&&Object.defineProperty(n,Symbol.toStringTag,{value:"Module"}),Object.defineProperty(n,"__esModule",{value:!0})};var C={};(()=>{h.r(C),h.d(C,{default:()=>J});const n=ir();var i=h.n(n),c=h(184),u=h.n(c),s=h(697),o=h.n(s),l=h(28);function a(r){return a=typeof Symbol=="function"&&typeof Symbol.iterator=="symbol"?function(e){return typeof e}:function(e){return e&&typeof Symbol=="function"&&e.constructor===Symbol&&e!==Symbol.prototype?"symbol":typeof e},a(r)}var m=["className","type"],j=["className"];function w(r,e,t){return(e=(function(f){var d=(function(b,x){if(a(b)!=="object"||b===null)return b;var O=b[Symbol.toPrimitive];if(O!==void 0){var g=O.call(b,"string");if(a(g)!=="object")return g;throw new TypeError("@@toPrimitive must return a primitive value.")}return String(b)})(f);return a(d)==="symbol"?d:String(d)})(e))in r?Object.defineProperty(r,e,{value:t,enumerable:!0,configurable:!0,writable:!0}):r[e]=t,r}function P(){return P=Object.assign?Object.assign.bind():function(r){for(var e=1;e<arguments.length;e++){var t=arguments[e];for(var f in t)Object.prototype.hasOwnProperty.call(t,f)&&(r[f]=t[f])}return r},P.apply(this,arguments)}function p(r,e){if(r==null)return{};var t,f,d=(function(x,O){if(x==null)return{};var g,S,_={},A=Object.keys(x);for(S=0;S<A.length;S++)g=A[S],O.indexOf(g)>=0||(_[g]=x[g]);return _})(r,e);if(Object.getOwnPropertySymbols){var b=Object.getOwnPropertySymbols(r);for(f=0;f<b.length;f++)t=b[f],e.indexOf(t)>=0||Object.prototype.propertyIsEnumerable.call(r,t)&&(d[t]=r[t])}return d}function D(r,e){return(function(t){if(Array.isArray(t))return t})(r)||(function(t,f){var d=t==null?null:typeof Symbol<"u"&&t[Symbol.iterator]||t["@@iterator"];if(d!=null){var b,x,O,g,S=[],_=!0,A=!1;try{if(O=(d=d.call(t)).next,f!==0)for(;!(_=(b=O.call(d)).done)&&(S.push(b.value),S.length!==f);_=!0);}catch(R){A=!0,x=R}finally{try{if(!_&&d.return!=null&&(g=d.return(),Object(g)!==g))return}finally{if(A)throw x}}return S}})(r,e)||I(r,e)||(function(){throw new TypeError(`Invalid attempt to destructure non-iterable instance.
In order to be iterable, non-array objects must have a [Symbol.iterator]() method.`)})()}function I(r,e){if(r){if(typeof r=="string")return k(r,e);var t=Object.prototype.toString.call(r).slice(8,-1);return t==="Object"&&r.constructor&&(t=r.constructor.name),t==="Map"||t==="Set"?Array.from(r):t==="Arguments"||/^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(t)?k(r,e):void 0}}function k(r,e){(e==null||e>r.length)&&(e=r.length);for(var t=0,f=new Array(e);t<e;t++)f[t]=r[t];return f}var N=(0,n.forwardRef)((function(r,e){var t=r.value,f=r.length,d=r.validChars,b=r.placeholder,x=r.autoFocus,O=r.passwordMode,g=r.inputProps,S=r.containerProps,_=r.classNames,A=r.onChange,R=r.onFocus,q=r.onBlur,V=r.onComplete,W=D((0,n.useState)(""),2),Q=W[0],X=W[1],Z=D((0,n.useState)(!1),2),H=Z[0],$=Z[1],M=(0,n.useRef)(null);(0,n.useEffect)((function(){x&&M.current.focus()}),[x]);var F,rr=function(){M.current.focus()},E=function(){return t??Q},nr=g.className,er=g.type,tr=p(g,m),or=S.className,ar=p(S,j);return i().createElement("div",{className:"vi__wrapper"},i().createElement("input",P({"aria-label":"verification input",spellCheck:!1,value:E(),onChange:function(y){var v=y.target.value.replace(/\s/g,"");RegExp("^[".concat(d,"]{0,").concat(f,"}$")).test(v)&&(A&&A?.(v),X(v),v.length===f&&V?.(v))},ref:function(y){M.current=y,typeof e=="function"?e(y):e&&(e.current=y)},className:u()("vi",nr),onKeyDown:function(y){["ArrowLeft","ArrowRight","ArrowUp","ArrowDown"].includes(y.key)&&y.preventDefault()},onFocus:function(){$(!0),R?.()},onBlur:function(){$(!1),q?.()},onSelect:function(y){var v=y.target.value;y.target.setSelectionRange(v.length,v.length)},type:O?"password":er},tr)),i().createElement("div",P({"data-testid":"container",className:u()("vi__container",_.container,or),onClick:function(){return M.current.focus()}},ar),(F=Array(f),(function(y){if(Array.isArray(y))return k(y)})(F)||(function(y){if(typeof Symbol<"u"&&y[Symbol.iterator]!=null||y["@@iterator"]!=null)return Array.from(y)})(F)||I(F)||(function(){throw new TypeError(`Invalid attempt to spread non-iterable instance.
In order to be iterable, non-array objects must have a [Symbol.iterator]() method.`)})()).map((function(y,v){var T;return i().createElement("div",{className:u()("vi__character",_.character,(T={"vi__character--selected":(E().length===v||E().length===v+1&&f===v+1)&&H},w(T,_.characterSelected,(E().length===v||E().length===v+1&&f===v+1)&&H),w(T,"vi__character--inactive",E().length<v),w(T,_.characterInactive,E().length<v),T)),onClick:rr,id:"field-".concat(v),"data-testid":"character-".concat(v),key:v},O&&E()[v]?"*":E()[v]||b)}))),i().createElement("style",{dangerouslySetInnerHTML:{__html:l.Z}}))}));N.displayName="VerificationInput",N.propTypes={value:o().string,length:o().number,validChars:o().string,placeholder:o().string,autoFocus:o().bool,passwordMode:o().bool,inputProps:o().object,containerProps:o().object,classNames:o().shape({container:o().string,character:o().string,characterInactive:o().string,characterSelected:o().string}),onChange:o().func,onFocus:o().func,onBlur:o().func,onComplete:o().func},N.defaultProps={length:6,validChars:"A-Za-z0-9",placeholder:"·",autoFocus:!1,inputProps:{},containerProps:{},classNames:{}};const J=N})();var B=Y;for(var U in C)B[U]=C[U];C.__esModule&&Object.defineProperty(B,"__esModule",{value:!0})})()})(L)),L}var ur=lr();const pr=cr(ur);export{pr as V};
