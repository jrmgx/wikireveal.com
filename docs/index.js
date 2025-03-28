(function main() {
  // For debug
  const log = (message) => console.log(message);
  // eslint-disable-next-line no-unused-vars
  // const log = (message) => {};

  // From template
  const { uiMessages } = window;
  const { commonWords } = window;
  const { winHashes } = window;
  const { puzzleId } = window;

  // State
  const hashes = [];
  let currentHighlightedHash = '';
  let highlightedHashesIndex = 0;

  // DOM
  const guessInput = document.getElementById('wz-input-guess');
  const sendAction = document.getElementById('wz-action-send');
  const sendForm = document.getElementById('wz-form-send');
  const listTriesElement = document.getElementById('wz-list-tries');
  const messageSendElement = document.getElementById('wz-message-send');
  const textElement = document.querySelector('.wz-text');

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

  /**
   * Show a message in the UI
   * @param message {string} The message to show
   */
  const showMessageToUser = (message) => {
    messageSendElement.innerHTML = message;
    messageSendElement.classList.add('wz-show');
    setTimeout(() => {
      messageSendElement.classList.remove('wz-show');
      messageSendElement.innerHTML = '';
    }, 2500);
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
    return reveal(`[data-hash="${hash}"]`);
  };

  /**
   * Highlight words with the given hash
   * @param hash {string} The hash
   */
  const highlight = (hash) => {
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
    const words = document.querySelectorAll(`[data-hash="${currentHighlightedHash}"]`);
    highlightedHashesIndex %= words.length;
    words.forEach((element) => {
      element.classList.add('wz-highlight');
      element.classList.remove('wz-highlight-current');
      if (i === highlightedHashesIndex) {
        element.classList.add('wz-highlight-current');
        element.scrollIntoView({
          behavior: 'smooth',
          block: 'center',
          inline: 'center',
        });
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
    const hash = sha1(normalized);

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
   */
  const sendWord = (word) => {
    log(`Sent word "${word}" ...`);
    stopAllHighlights();

    if (word.length === 0) {
      return;
    }

    const normalized = normalize(word);
    const hash = sha1(normalized);

    if (hashes.indexOf(hash) !== -1) {
      showMessageToUser(uiMessages.already_sent);
      guessInput.value = '';
      return;
    }

    hashes.push(hash);

    const winHashIndex = winHashes.indexOf(hash);
    if (winHashIndex !== -1) {
      winHashes.splice(winHashIndex, 1);
    }

    // Win condition
    if (winHashes.length === 0) {
      showMessageToUser(uiMessages.victory);
      revealAll();
    }

    highlight(hash);

    const count = revealHash(hash);
    addToList(hash, hashes.length - commonWords.length, word, count);
    saveWord(word);

    guessInput.value = '';
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
      showMessageToUser(uiMessages.victory);
      revealAll();
    }

    const count = revealHash(hash);
    addToList(hash, hashes.length - commonWords.length, word, count);
  };

  on(listTriesElement, 'click', 'div', (e) => {
    e.preventDefault();
    const div = e.target.parentNode;
    const thatHash = div.dataset.highlight || '';

    highlight(thatHash);
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

  // Load the game
  log(`Loading game for puzzle id "${puzzleId}" ...`);
  commonWords.forEach(insertCommonWord);

  // Reload data
  const item = localStorage.getItem(puzzleId);
  let savedState = [];
  if (item) {
    log(`Reload game state for puzzle id "${puzzleId}"`);
    savedState = JSON.parse(item);
    savedState.forEach(insertReplayWord);
  } else {
    log(`No game state for puzzle id "${puzzleId}", clearing previous`);
    localStorage.clear(); // Clear all previous plays TODO is this really useful?
  }
}());
