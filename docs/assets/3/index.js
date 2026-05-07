function main(
    addToListHandler = null,
    winMessageHandler = null,
    alreadySentMessageHandler = null,
) {
  // For debug
  // const log = (message) => console.log(message);
  // eslint-disable-next-line no-unused-vars
  const log = (_message) => {};

  // From template
  const { uiMessages } = window;
  const { commonWords } = window;
  const { winHashes } = window;
  const { puzzleId } = window;

  // State
  const hashes = [];
  let currentHighlightedHash = '';
  let highlightedHashesIndex = 0;
  let wantFocusBack = null;
  let autoscroll = true;
  let letterCountsVisible = false;

  // DOM
  const uiPanelElement = document.querySelector('.wz-ui');
  const guessInput = document.getElementById('wz-input-guess');
  const autoscrollCheckbox = document.getElementById('wz-autoscroll');
  const sendAction = document.getElementById('wz-action-send');
  const scrollTopAction = document.querySelector('.wz-top');
  const sendForm = document.getElementById('wz-form-send');
  const listTriesElement = document.getElementById('wz-list-tries');
  const messageSendElement = document.getElementById('wz-message-send');
  const textElement = document.querySelector('.wz-text');

  const setAutoscroll = (enabled) => {
    autoscroll = Boolean(enabled);
    autoscrollCheckbox.checked = autoscroll;
    localStorage.setItem('autoscroll', autoscroll.toString());
  };

  /**
   * @param id {string} lang-YYYYMMDD
   * @return {string} YYYYMMDD
   */
  const parsePuzzleIdDate = (id) => id.split('-')[1];

  const PUZZLE_STORAGE_RETENTION_DAYS = 94;
  const PUZZLE_STORAGE_KEY_RE = /^[a-z]{2}-\d{8}$/;

  const utcYmdFromDate = (d) => (
    d.getUTCFullYear() * 10000 + (d.getUTCMonth() + 1) * 100 + d.getUTCDate()
  );

  const utcTodayYmd = () => utcYmdFromDate(new Date());

  const addDaysToUtcYmd = (ymd, deltaDays) => {
    const y = Math.floor(ymd / 10000);
    const m = Math.floor((ymd % 10000) / 100) - 1;
    const day = ymd % 100;
    const d = new Date(Date.UTC(y, m, day));
    d.setUTCDate(d.getUTCDate() + deltaDays);
    return utcYmdFromDate(d);
  };

  /**
   * Drop puzzle saves older than PUZZLE_STORAGE_RETENTION_DAYS (calendar days, UTC, today inclusive).
   * Other keys (e.g. autoscroll) are left untouched.
   */
  const pruneStalePuzzleStorage = () => {
    const cutoffYmd = addDaysToUtcYmd(
      utcTodayYmd(),
      -(PUZZLE_STORAGE_RETENTION_DAYS - 1),
    );
    const keys = [];
    for (let i = 0; i < localStorage.length; i += 1) {
      keys.push(localStorage.key(i));
    }
    keys.forEach((key) => {
      if (!key || !PUZZLE_STORAGE_KEY_RE.test(key)) {
        return;
      }
      const datePart = parseInt(parsePuzzleIdDate(key), 10);
      if (Number.isNaN(datePart) || datePart < cutoffYmd) {
        localStorage.removeItem(key);
      }
    });
  };

  /**
   * Delegate event
   * @see from https://stackoverflow.com/a/56570910/696517
   */
  const on = (element, type, selector, handler) => {
    element.addEventListener(type, (event) => {
      if (event.target.closest(selector)) {
        handler(event);
      }
    });
  };

  const shareWin = (_e) => {
    const navigatorShare = window.navigator;
    // noinspection JSUnresolvedReference
    const title = uiMessages.share_public.replace('999', hashes.length - commonWords.length);
    log(title);
    const shareObject = { title, url: document.location };
    if (navigatorShare.share && navigatorShare.canShare && navigatorShare.canShare(shareObject)) {
      navigatorShare.share(shareObject)
        .then(() => { log('Share succeed!'); })
        .catch((error) => {
          // eslint-disable-next-line no-alert
          // noinspection JSUnresolvedReference
          prompt(uiMessages.share_error, `${title} ${shareObject.url}`);
          log(error);
        });
    } else {
      // eslint-disable-next-line no-alert
      // noinspection JSUnresolvedReference
      prompt(uiMessages.share_error, `${title} ${shareObject.url}`);
    }
  };

  const showWinMessage = () => {
    if (winMessageHandler) {
      winMessageHandler();
      return;
    }

    // noinspection JSUnresolvedReference
    messageSendElement.innerHTML = `<span>${uiMessages.victory}</span><br><a href="#" class="wz-share">${uiMessages.share}</a>`;
    messageSendElement.addEventListener('click', (e) => {
      shareWin(e);
      e.preventDefault();
      e.stopPropagation();
    });
    messageSendElement.classList.add('wz-show');
  };

  const showAlreadySentMessage = () => {
    if (alreadySentMessageHandler) {
      alreadySentMessageHandler();
      return;
    }

    // noinspection JSUnresolvedReference
    messageSendElement.innerHTML = uiMessages.already_sent;
    setTimeout(() => {
      messageSendElement.classList.remove('wz-show');
      messageSendElement.innerHTML = '';
    }, 2500);
    messageSendElement.classList.add('wz-show');
  };

  /**
   * Add the given word to the UI list
   * @param hash {string}
   * @param tries {number}
   * @param word {string}
   * @param count {number}
   */
  const addToList = (hash, tries, word, count) => {
    listTriesElement.insertAdjacentHTML(
      'afterbegin',
      `<div data-highlight="${hash}"><span class="wz-tries">#${tries}</span><span class="wz-word">${word}</span><span>${count}</span></div>`,
    );
    // Scroll list to top
    uiPanelElement.scrollTop = 0;
    if (addToListHandler) {
      addToListHandler(hash, tries, word, count);
    }
  };

  /**
   * Remove current words highlighting
   */
  const stopAllHighlights = () => {
    highlightedHashesIndex = 0;
    currentHighlightedHash = '';
    document.querySelectorAll('.wz-highlight').forEach((element) => {
      element.classList.remove('wz-highlight');
      element.classList.remove('wz-highlight-current');
    });
  };

  /**
   * Reveal words for the given selector
   * @param selector {string} The selector
   * @return {number} The number of words revealed
   */
  const reveal = (selector) => {
    const wordElements = document.querySelectorAll(selector);
    wordElements.forEach((element) => {
      delete element.dataset.wzCountBackup;
      element.classList.remove('wz-w-hide');
      // eslint-disable-next-line no-param-reassign
      element.innerHTML = decodeURIComponent(atob(element.dataset.word));
    });
    return wordElements.length;
  };

  /**
   * Reveal all, used when the player wins
   * @return {number} The number of word revealed
   */
  const revealAll = () => reveal('.wz-w-hide');

  /**
   * Reveal words based on their hash
   * @param hash {string} The hash
   * @return {number} The number of word revealed
   */
  const revealHash = (hash) => {
    log(`Revealing hash ${hash}`);
    return reveal(`[data-hash*="${hash}"]`);
  };

  /**
   * Highlight words with the given hash
   * @param hash {string} The hash
   * @param forceScroll {boolean} Force scroll to the first highlighted word
   */
  const highlight = (hash, forceScroll= false) => {
    if (currentHighlightedHash.length === 0) {
      currentHighlightedHash = hash;
      highlightedHashesIndex = 0;
    } else if (currentHighlightedHash === hash) {
      highlightedHashesIndex += 1;
    } else {
      stopAllHighlights();
      currentHighlightedHash = hash;
      highlightedHashesIndex = 0;
    }

    let i = 0;
    const words = document.querySelectorAll(`[data-hash*="${currentHighlightedHash}"]`);
    highlightedHashesIndex %= words.length;
    words.forEach((element) => {
      element.classList.add('wz-highlight');
      element.classList.remove('wz-highlight-current');
      if (i === highlightedHashesIndex) {
        element.classList.add('wz-highlight-current');
        if (forceScroll || autoscroll) {
          element.scrollIntoView({
            behavior: 'smooth',
            block: 'center',
            inline: 'center',
          });
        }
      }
      i += 1;
    });
  };

  /**
   * Save the given word to puzzle state
   * @param word {string} The word
   */
  const saveWord = (word) => {
    log(`Save word "${word}" to replay state`);
    const item = localStorage.getItem(puzzleId);
    let savedState = [];
    if (item) {
      savedState = JSON.parse(item);
    }
    savedState.push(word);
    localStorage.setItem(puzzleId, JSON.stringify(savedState));
  };

  /**
   * Normalize the given word
   * @param word {string} The word
   * @return {string} Normalized version
   */
  const normalize = (word) => word
    .toLowerCase()
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '');

  /**
   * Add word hash to found hashes
   * @param word {string} The word
   * @return {string} The hash
   */
  const insertWord = (word) => {
    const normalized = normalize(word);
    // noinspection JSUnresolvedReference
    const hash = sha1(normalized).substring(0, 10);

    hashes.push(hash);

    const winHashIndex = winHashes.indexOf(hash);
    if (winHashIndex !== -1) {
      winHashes.splice(winHashIndex, 1);
    }

    return hash;
  };

  /**
   * Action when the player enter a new guess
   * @param word {string} The word
   * @return null when already sent (or if the word is empty)
   */
  const sendWord = (word) => {
    log(`Sent word "${word}" ...`);
    stopAllHighlights();

    if (word.length === 0) {
      return null;
    }

    const normalized = normalize(word);
    // noinspection JSUnresolvedReference
    const hash = sha1(normalized).substring(0, 10);

    if (hashes.indexOf(hash) !== -1) {
      showAlreadySentMessage()
      guessInput.value = '';
      return null;
    }

    insertWord(word);

    // Win condition
    if (winHashes.length === 0) {
      showWinMessage()
      revealAll();
    }

    highlight(hash);

    const count = revealHash(hash);
    addToList(hash, hashes.length - commonWords.length, word, count);
    saveWord(word);

    guessInput.value = '';

    return hash;
  };

  /**
   * Reveal the given common word
   * @param word {string} The word
   */
  const insertCommonWord = (word) => {
    log(`Add "${word}" to common words`);
    const hash = insertWord(word);
    revealHash(hash);
  };

  /**
   * When re-loading the page this is called to restaure the saved game state.
   * @param word {string} The word
   */
  const insertReplayWord = (word) => {
    log(`Add "${word}" to replayed words`);
    const hash = insertWord(word);

    // Win condition
    if (winHashes.length === 0) {
      showWinMessage();
      revealAll();
    }

    const count = revealHash(hash);
    addToList(hash, hashes.length - commonWords.length, word, count);
  };

  const applyLetterCountsVisibility = () => {
    document.querySelectorAll('.wz-words span.wz-w-hide').forEach((span) => {
      const size = Number(span.dataset.size);
      if (Number.isNaN(size) || size <= 3) {
        return;
      }
      if (letterCountsVisible) {
        if (span.dataset.wzCountBackup === undefined) {
          span.dataset.wzCountBackup = span.innerHTML;
        }
        const digits = String(size);
        const pad = Math.max(0, size - digits.length);
        span.innerHTML = `${'&nbsp;'.repeat(pad)}${digits}`;
      } else if (span.dataset.wzCountBackup !== undefined) {
        span.innerHTML = span.dataset.wzCountBackup;
        delete span.dataset.wzCountBackup;
      }
    });
  };

  /**
   * @param show {boolean} Whether letter counts are shown on hidden words (length > 3 only)
   */
  const setShowLetterCounts = (show) => {
    letterCountsVisible = Boolean(show);
    applyLetterCountsVisibility();
  };

  on(listTriesElement, 'click', 'div', (e) => {
    e.preventDefault();
    const div = e.target.parentNode;
    const thatHash = div.dataset.highlight || '';

    highlight(thatHash, true);
  });

  on(document.querySelector('.wz-words'), 'click', 'span.wz-w-hide', (e) => {
    e.preventDefault();
    setShowLetterCounts(!letterCountsVisible);
  });

  /**
   * Send a word DOM event
   * @param event {event} The event
   */
  const evenListener = (event) => {
    // noinspection JSUnresolvedReference
    event.preventDefault();
    sendWord(guessInput.value.trim());
  };

  textElement.addEventListener('click', (e) => {
    e.preventDefault();
    stopAllHighlights();
  });

  const scrollToTop = () => {
    document.querySelector('.wz-text').scrollTo({
      top: 0, left: 0, behavior: 'smooth',
    });
  }

  sendAction.addEventListener('click', evenListener);
  sendForm.addEventListener('submit', evenListener);
  autoscrollCheckbox.addEventListener('change', (e) => {
    log(`Autoscroll is now ${e.target.checked}`);
    setAutoscroll(e.target.checked);
  });
  if (scrollTopAction) {
    scrollTopAction.addEventListener('click', (e) => {
      e.preventDefault();
      scrollToTop();
    });
  }

  // Load the game
  log(`Loading game for puzzle id "${puzzleId}" ...`);
  commonWords.forEach(insertCommonWord);

  // Autoscroll state
  const autoscrollLocalStorage = localStorage.getItem('autoscroll');
  if (autoscrollLocalStorage !== null) {
    log(`Restoring previous autoscroll state: "${autoscrollLocalStorage}"`);
    setAutoscroll(autoscrollLocalStorage === true.toString());
  }

  // Reload data
  const puzzleLocalStorage = localStorage.getItem(puzzleId);
  let savedState = [];
  if (puzzleLocalStorage) {
    log(`Reload game state for puzzle id "${puzzleId}"`);
    savedState = JSON.parse(puzzleLocalStorage);
    savedState.forEach(insertReplayWord);
  }

  log('Pruning stale puzzle storage');
  pruneStalePuzzleStorage();

  // Handling focus
  if (!window.matchMedia('(max-width: 768px)').matches) {
    document.addEventListener('click', () => {
      if (wantFocusBack) {
        clearTimeout(wantFocusBack);
      }
      wantFocusBack = setTimeout(() => {
        wantFocusBack = null;
        guessInput.focus();
      }, 1000);
    });
    guessInput.focus();
  }

  window.wzGame = {
    highlight,
    scrollToTop,
    sendWord,
    setAutoscroll,
    setShowLetterCounts,
  };
}
if (document.location.hash !== '#mobile') {
  document.querySelector('.wz-top').style.display = 'block';
  document.querySelector('.wz-ui').style.display = 'block';
  main();
}
