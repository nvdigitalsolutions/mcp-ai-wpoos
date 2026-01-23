
'use strict'

if (process.env.NODE_ENV === 'production') {
  module.exports = require('./subtitle.cjs.production.min.js')
} else {
  module.exports = require('./subtitle.cjs.development.js')
}
