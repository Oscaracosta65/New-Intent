(function(){
  "use strict";

  function q(root, sel){ return root ? root.querySelector(sel) : null; }
  function qa(root, sel){ return root ? root.querySelectorAll(sel) : []; }

  function clampInt(v, min, max, fallback){
    var n = parseInt(String(v), 10);
    if (isNaN(n)) { n = fallback; }
    if (n < min) { n = min; }
    if (n > max) { n = max; }
    return n;
  }

  function toast(root, msg, persist, type){
    var t = q(root, ".le-toast");
    if (!t) return;
    t.textContent = msg;
    
    // Remove old type classes
    t.classList.remove("le-toast--loading", "le-toast--success", "le-toast--error");
    
    // Add new type class if specified
    if (type) {
      t.classList.add("le-toast--" + type);
    }
    
    t.classList.add("on");
    window.clearTimeout(t.__le_to);
    if (!persist) {
      t.__le_to = window.setTimeout(function(){ t.classList.remove("on"); }, 1800);
    }
  }

  function hideToast(root){
    var t = q(root, ".le-toast");
    if (!t) return;
    window.clearTimeout(t.__le_to);
    t.classList.remove("on");
  }

  function el(tag, cls, txt){
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (txt !== undefined) e.textContent = txt;
    return e;
  }

  function renderPicks(root, payload){
    var wrap = q(root, ".le-picks");
    if (!wrap) return;

    wrap.innerHTML = "";

    var picks = (payload && payload.picks) ? payload.picks : [];
    var rules = payload && payload.rules ? payload.rules : {};
    var extraEnabled = !!rules.extraEnabled;
    var extraLabel = rules.extraLabel || "X";

    for (var i = 0; i < picks.length; i++){
      var line = picks[i];

      var row = el("div", "le-line");
      var left = el("div", "le-line-left");
      var badge = el("div", "le-line-badge", "Line " + (i+1));
      left.appendChild(badge);

      var balls = el("div", "le-balls");

      // Main balls
      for (var m = 0; m < line.main.length; m++){
        balls.appendChild(el("span", "le-ball le-ball--main", String(line.main[m])));

        // Dash separator between main balls
        if (m < line.main.length - 1){
          balls.appendChild(el("span", "le-sep", " - "));
        }
      }

      // Extra balls
      if (extraEnabled && line.extra && line.extra.length){
        balls.appendChild(el("span", "le-sep le-sep--pipe", " | "));
        balls.appendChild(el("span", "le-ball-label", extraLabel));

        for (var x = 0; x < line.extra.length; x++){
          balls.appendChild(el("span", "le-ball le-ball--extra", String(line.extra[x])));
          if (x < line.extra.length - 1){
            balls.appendChild(el("span", "le-sep", " - "));
          }
        }
      }

      left.appendChild(balls);

      var actions = el("div", "le-line-actions");
      var copyBtn = el("button", "le-icon-btn", "Copy");
      copyBtn.type = "button";
      copyBtn.addEventListener("click", (function(main, extra){
        return function(){
          var txt = main.join("-");
          if (extra && extra.length){
            txt += " | " + extra.join("-");
          }
          if (navigator.clipboard && navigator.clipboard.writeText){
            navigator.clipboard.writeText(txt).then(function(){
              toast(root, "✓ Copied to clipboard!", false, "success");
            }).catch(function(){
              toast(root, "⚠ Copy failed", false, "error");
            });
          } else {
            toast(root, "⚠ Clipboard not available", false, "error");
          }
        };
      })(line.main || [], line.extra || []));

      actions.appendChild(copyBtn);

      row.appendChild(left);
      row.appendChild(actions);

      wrap.appendChild(row);
    }
  }

  function fetchPicks(root, method, lines){
    var moduleId = root.getAttribute("data-module-id");
    var gameKey = root.getAttribute("data-game") || "game";

    var base = (window.LEInstantIntent && window.LEInstantIntent[gameKey] && window.LEInstantIntent[gameKey].ajaxUrl)
      ? window.LEInstantIntent[gameKey].ajaxUrl
      : (window.location.origin + "/index.php?option=com_ajax&module=le_instantintent&method=getPicks&format=json");

    var url = base
      + "&module_id=" + encodeURIComponent(moduleId)
      + "&pick_method=" + encodeURIComponent(method)
      + "&lines=" + encodeURIComponent(lines);

    var btn = q(root, ".le-generate");
    
    // Show loading state
    if (btn) {
      btn.classList.add("le-loading");
      btn.disabled = true;
    }
    toast(root, "Generating picks…", true, "loading");

    return fetch(url, { credentials: "same-origin" })
      .then(function(r){ 
        if (!r.ok) {
          throw new Error("Server error: " + r.status);
        }
        return r.json(); 
      })
      .then(function(j){
        if (!j || j.ok !== true){
          throw new Error((j && j.error) ? j.error : "Unknown error");
        }
        renderPicks(root, j);
        toast(root, "✓ Picks generated successfully!", false, "success");
        return j;
      })
      .catch(function(err){
        toast(root, "⚠ Error: " + err.message, true, "error");
        console.error("Failed to fetch picks:", err);
        return null;
      })
      .finally(function(){
        // Remove loading state
        if (btn) {
          btn.classList.remove("le-loading");
          btn.disabled = false;
        }
        // Auto-hide error toast after 5 seconds
        window.setTimeout(function(){ hideToast(root); }, 5000);
      });
  }

  function initOne(root){
    var methodSel = q(root, ".le-method");
    var linesInp  = q(root, ".le-lines");
    var btn       = q(root, ".le-generate");
    var gameKey   = root.getAttribute("data-game") || "game";

    // Initial render from embedded payload
    if (window.LEInstantIntent && window.LEInstantIntent[gameKey]){
      renderPicks(root, window.LEInstantIntent[gameKey]);
    }

    if (btn){
      btn.addEventListener("click", function(){
        var method = methodSel ? methodSel.value : "random";
        var lines  = clampInt(linesInp ? linesInp.value : "5", 1, 50, 5);
        fetchPicks(root, method, lines);
      });
    }
  }

  document.addEventListener("DOMContentLoaded", function(){
    var mods = document.querySelectorAll(".le-ii-mod");
    for (var i = 0; i < mods.length; i++){
      initOne(mods[i]);
    }
  });
})();
