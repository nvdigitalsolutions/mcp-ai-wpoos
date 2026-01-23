'use strict';

Object.defineProperty(exports, '__esModule', { value: true });

var padLeft = function padLeft(value, length) {
  if (length === void 0) {
    length = 2;
  }

  return value.toString().padStart(length, '0');
};

function formatTimestamp(timestamp, options) {
  if (options === void 0) {
    options = {
      format: 'srt'
    };
  }

  var date = new Date(0, 0, 0, 0, 0, 0, timestamp);
  var hours = date.getHours();
  var minutes = date.getMinutes();
  var seconds = date.getSeconds();
  var ms = timestamp - (hours * 3600000 + minutes * 60000 + seconds * 1000);
  return padLeft(hours) + ":" + padLeft(minutes) + ":" + padLeft(seconds) + (options.format === 'vtt' ? '.' : ',') + padLeft(ms, 3);
}

function _extends() {
  _extends = Object.assign || function (target) {
    for (var i = 1; i < arguments.length; i++) {
      var source = arguments[i];

      for (var key in source) {
        if (Object.prototype.hasOwnProperty.call(source, key)) {
          target[key] = source[key];
        }
      }
    }

    return target;
  };

  return _extends.apply(this, arguments);
}

function parseTimestamp(timestamp) {
  var match = timestamp.match(/^(?:(\d{1,}):)?(\d{2}):(\d{2})[,.](\d{3})$/);

  if (!match) {
    throw new Error('Invalid SRT or VTT time format: "' + timestamp + '"');
  }

  var hours = match[1] ? parseInt(match[1], 10) * 3600000 : 0;
  var minutes = parseInt(match[2], 10) * 60000;
  var seconds = parseInt(match[3], 10) * 1000;
  var milliseconds = parseInt(match[4], 10);
  return hours + minutes + seconds + milliseconds;
}

var RE_TIMESTAMP = /^((?:\d{1,}:)?\d{2}:\d{2}[,.]\d{3}) --> ((?:\d{1,}:)?\d{2}:\d{2}[,.]\d{3})(?: (.*))?$/;
function parseTimestamps(value) {
  var match = RE_TIMESTAMP.exec(value);

  if (!match) {
    throw new Error('Invalid timestamp format');
  }

  var timestamp = {
    start: parseTimestamp(match[1]),
    end: parseTimestamp(match[2])
  };

  if (match[3]) {
    timestamp.settings = match[3];
  }

  return timestamp;
}

var normalize = function normalize(str) {
  return str.trim().concat('\n').replace(/\r\n/g, '\n').replace(/\n{3,}/g, '\n\n').replace(/^WEBVTT.*\n(?:.*: .*\n)*\n/, '').split('\n');
};

var isIndex = function isIndex(str) {
  return /^\d+$/.test(str.trim());
};

var isTimestamp = function isTimestamp(str) {
  return RE_TIMESTAMP.test(str);
};

var throwError = function throwError(expected, index, row) {
  throw new Error("expected " + expected + " at row " + (index + 1) + ", but received " + row);
};

function parse(input) {
  var source = normalize(input);
  var state = {
    expect: 'index',
    caption: {
      start: 0,
      end: 0,
      text: ''
    },
    captions: []
  };
  source.forEach(function (row, index) {
    if (state.expect === 'index') {
      state.expect = 'timestamp';

      if (isIndex(row)) {
        return;
      }
    }

    if (state.expect === 'timestamp') {
      if (!isTimestamp(row)) {
        throwError('timestamp', index, row);
      }

      state.caption = _extends({}, state.caption, parseTimestamps(row));
      state.expect = 'text';
      return;
    }

    if (state.expect === 'text') {
      if (isTimestamp(source[index + 1])) {
        state.expect = 'timestamp';
        state.captions.push(state.caption);
        state.caption = {
          start: 0,
          end: 0,
          text: ''
        };
        return;
      }

      var isLastRow = index === source.length - 1;
      var isNextRowCaption = isIndex(source[index + 1] || '') && isTimestamp(source[index + 2]);

      if (isLastRow || isNextRowCaption) {
        state.expect = 'index';
        state.captions.push(state.caption);
        state.caption = {
          start: 0,
          end: 0,
          text: ''
        };
      } else {
        state.caption.text = state.caption.text ? state.caption.text + '\n' + row : row;
      }
    }
  });
  return state.captions;
}

function resync(captions, time) {
  return captions.map(function (caption) {
    return _extends({}, caption, {
      start: caption.start + time,
      end: caption.end + time
    });
  });
}

function stringify(captions, options) {
  if (options === void 0) {
    options = {
      format: 'srt'
    };
  }

  var isVTT = options.format === 'vtt';
  return (isVTT ? 'WEBVTT\n\n' : '') + captions.map(function (caption, index) {
    return (index > 0 ? '\n' : '') + [index + 1, formatTimestamp(caption.start, options) + " --> " + formatTimestamp(caption.end, options) + (isVTT && caption.settings ? ' ' + caption.settings : ''), caption.text].join('\n');
  }).join('\n') + '\n';
}

exports.RE_TIMESTAMP = RE_TIMESTAMP;
exports.formatTimestamp = formatTimestamp;
exports.parse = parse;
exports.parseTimestamp = parseTimestamp;
exports.parseTimestamps = parseTimestamps;
exports.resync = resync;
exports.stringify = stringify;
//# sourceMappingURL=subtitle.cjs.development.js.map
