(function main() {
  // For debug
  // const log = (message) => console.log(message);
  // eslint-disable-next-line no-unused-vars
  const log = (message) => {};

  // From template
  const { uiMessages } = window;
  const { commonWords } = window;
  const { puzzleId } = window;
  const { solutionChecker } = window;

  // State
  const hashes = [];
  const winFoundHashes = {};
  let currentHighlightedHash = '';
  let highlightedHashesIndex = 0;
  let wantFocusBack = null;
  let autoscroll = true;

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

  /**
   * @param id {string} lang-YYYYMMDD
   * @return {string} YYYYMMDD
   */
  const parsePuzzleIdDate = (id) => id.split('-')[1];

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

  const shareWin = (e) => {
    const navigatorShare = window.navigator;
    const title = uiMessages.share_public.replace('999', hashes.length - commonWords.length);
    log(title);
    const shareObject = { title, url: document.location };
    if (navigatorShare.share && navigatorShare.canShare && navigatorShare.canShare(shareObject)) {
      navigatorShare.share(shareObject)
        .then(() => { log('Share succeed!'); })
        .catch((error) => {
          // eslint-disable-next-line no-alert
          prompt(uiMessages.share_error, `${title} ${shareObject.url}`);
          log(error);
        });
    } else {
      // eslint-disable-next-line no-alert
      prompt(uiMessages.share_error, `${title} ${shareObject.url}`);
    }

    e.preventDefault();
    e.stopPropagation();
  };

  /**
   * Show a message in the UI
   * @param message {string} The message to show
   * @param won {boolean} Show sharing button
   */
  const showMessageToUser = (message, won) => {
    messageSendElement.innerHTML = message;
    if (won) {
      messageSendElement.innerHTML = `<span>${message}</span><br><a href="#" class="wz-share">${uiMessages.share}</a>`;
      messageSendElement.addEventListener('click', shareWin);
    } else {
      setTimeout(() => {
        messageSendElement.classList.remove('wz-show');
        messageSendElement.innerHTML = '';
      }, 2500);
    }
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
  const reveal = (selector, word) => {
    const wordElements = document.querySelectorAll(selector);
    wordElements.forEach((element) => {
      element.classList.remove('wz-w-hide');
      // eslint-disable-next-line no-param-reassign
      element.innerHTML = word;
    });
    return wordElements.length;
  };

  /**
   * Reveal all, used when the player wins
   * @return {number} The number of word revealed
   */
  const revealAll = () => {
    log(`Revealing all words ...`);

    let rootPassword = '';
    solutionChecker.winHashes.forEach((element) => {
      rootPassword += winFoundHashes[element];
    });

    insertVariations(rootPassword, false);
  };

  /**
   * Reveal words based on their hash
   * @param hash {string} The hash
   * @return {number} The number of word revealed
   */
  const revealHash = (hash, word) => {
    log(`Revealing hash ${hash}, word ${word}`);
    return reveal(`[data-hash="${hash}"]`, word);
  };

  /**
   * Highlight words with the given hash
   * @param hash {string} The hash
   * @param forceScroll {boolean} Force scroll to the first highlighted word
   */
  const highlight = (hash, forceScroll) => {
    if (currentHighlightedHash.length === 0) {
      currentHighlightedHash = hash;
      highlightedHashesIndex = 0;
    } else if (currentHighlightedHash === hash) {
      highlightedHashesIndex += 1;
    } else {
      currentHighlightedHash = hash;
      highlightedHashesIndex = 0;
    }

    let i = 0;
    const words = document.querySelectorAll(`[data-hash="${currentHighlightedHash}"]`);
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
    const hash = sha1(word).substring(0, 10);
    hashes.push(hash);

    const normalized = normalize(word);
    const normalizedHash = sha1(normalized).substring(0, 10);

    if (solutionChecker.winHashes.includes(normalizedHash)) {
      winFoundHashes[normalizedHash] = normalized;
    }

    return hash;
  };

  /**
   * Action when the player enter a new guess
   * @param word {string} The word
   */
  const sendWord = (word) => {
    log(`Sent word "${word}" ...`);

    saveWord(word);

    stopAllHighlights();

    if (word.length === 0) {
      return;
    }

    const normalized = normalize(word);
    const hash = sha1(normalized).substring(0, 10);

    if (hashes.indexOf(hash) !== -1) {
      showMessageToUser(uiMessages.already_sent, false);
      guessInput.value = '';
      return;
    }

    insertWord(word);

    // Win condition
    if (solutionChecker.winHashes.length === Object.keys(winFoundHashes).length) {
      showMessageToUser(uiMessages.victory, true);
      revealAll();

      highlight(hash);

      return;
    }

    highlight(hash);

    let count = revealHash(hash, word);

    count += insertVariations(word);

    addToList(hash, hashes.length - commonWords.length, word, count);

    guessInput.value = '';
  };

  const insertVariations = (word, shouldHighlight = true) => {
    const normalized = normalize(word);
    const hash = sha1(normalized).substring(0, 10);

    let count = 0;

    if (solutionChecker.variationsMap[hash] !== undefined) {
      const variationsEncrypted = solutionChecker.variationsMap[hash];
      const salt = variationsEncrypted.substring(0, 12);
      const iv = variationsEncrypted.substring(12, 24 + 12);
      const ciphertext = variationsEncrypted.substring(24 + 12);

      const cipherParams = CryptoJS.lib.CipherParams.create({
        salt: CryptoJS.enc.Base64.parse(salt),
        iv: CryptoJS.enc.Base64.parse(iv),
        ciphertext: CryptoJS.enc.Base64.parse(ciphertext),
      })
      const variationsAsJson = CryptoJS.AES
        .decrypt(cipherParams, normalized)
        .toString(CryptoJS.enc.Utf8)
      ;
      const variations = JSON.parse(variationsAsJson);

      variations.forEach((variation) => {
        log(`Add variation "${variation}" ...`);

        const variationHash = sha1(variation).substring(0, 10);

        count += revealHash(variationHash, variation);

        if (shouldHighlight) {
          // highlight(variationHash);
        }
      });
    } else {
      log(`No variations found for "${word}"`);
    }

    return count;
  }

  /**
   * Reveal the given common word
   * @param word {string} The word
   */
  const insertCommonWord = (word) => {
    log(`Add "${word}" to common words`);
    const hash = insertWord(word);
    revealHash(hash, word);

    insertVariations(word, false);
  };

  /**
   * When re-loading the page this is called to restaure the saved game state.
   * @param word {string} The word
   */
  const insertReplayWord = (word) => {
    log(`Add "${word}" to replayed words`);
    const hash = insertWord(word);

    // Win condition
    if (solutionChecker.winHashes.length === Object.keys(winFoundHashes).length) {
      showMessageToUser(uiMessages.victory, true);
      revealAll();
    }

    const count = revealHash(hash, word);
    addToList(hash, hashes.length - commonWords.length, word, count);
  };

  on(listTriesElement, 'click', 'div', (e) => {
    e.preventDefault();
    const div = e.target.parentNode;
    const thatHash = div.dataset.highlight || '';

    highlight(thatHash, true);
  });

  on(document.querySelector('.wz-words'), 'click', 'span.wz-w-hide', (e) => {
    e.preventDefault();
    const span = e.target;
    const { size } = span.dataset;
    const prev = span.innerHTML;
    if (size < 3) {
      span.innerHTML = '&nbsp;'.repeat(size - 1) + size;
    } else {
      span.innerHTML = `${'&nbsp;'.repeat(size - 3)}(${size})`;
    }
    // back to initial state
    setTimeout(() => { span.innerHTML = prev; }, 1500);
  });

  /**
   * Send a word DOM event
   * @param event {event} The event
   */
  const evenListener = (event) => {
    event.preventDefault();
    sendWord(guessInput.value.trim());
  };

  textElement.addEventListener('click', (e) => {
    e.preventDefault();
    stopAllHighlights();
  });

  sendAction.addEventListener('click', evenListener);
  sendForm.addEventListener('submit', evenListener);
  autoscrollCheckbox.addEventListener('change', (e) => {
    log(`Autoscroll is now ${e.target.checked}`);
    autoscroll = e.target.checked;
    localStorage.setItem('autoscroll', autoscroll.toString());
  });
  scrollTopAction.addEventListener('click', (e) => {
    e.preventDefault();
    document.querySelector('.wz-text').scrollTo({
      top: 0, left: 0, behavior: 'smooth',
    });
  });

  // Load the game
  log(`Loading game for puzzle id "${puzzleId}" ...`);
  commonWords.forEach(insertCommonWord);

  // Autoscroll state
  const autoscrollLocalStorage = localStorage.getItem('autoscroll');
  if (autoscrollLocalStorage !== null) {
    log(`Restoring previous autoscroll state: "${autoscrollLocalStorage}"`);
    autoscroll = autoscrollLocalStorage === true.toString();
    autoscrollCheckbox.checked = autoscroll;
  }

  // Reload data
  const puzzleLocalStorage = localStorage.getItem(puzzleId);
  let savedState = [];
  if (puzzleLocalStorage) {
    log(`Reload game state for puzzle id "${puzzleId}"`);
    savedState = JSON.parse(puzzleLocalStorage);
    savedState.forEach(insertReplayWord);
  }

  log('Clearing previous game states');
  const puzzleDate = new RegExp(parsePuzzleIdDate(puzzleId));
  for (let i = 0; i < localStorage.length; i += 1) {
    const key = localStorage.key(i);
    if (!puzzleDate.test(key)) {
      localStorage.removeItem(key);
    }
  }

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
}());
