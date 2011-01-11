<?php

/**
 * Copyright (c) 2011 Rajesh Kumar <rajesh@meetrajesh.com>
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE. 
**/

class memq {

    private $q_name;

    public function __construct($q_name) {
        $this->q_name = $q_name;
        $this->start_key = 'start_' . $q_name;
        $this->end_key = 'end_' . $q_name;
        $this->lock_key = 'lock_' . $q_name;
        $this->item_key_format = 'item_' . $q_name . '_%d';
        $this->_connect();
        // create the start and end keys if they don't exist
        $this->memc->add($this->start_key, 0);
        $this->memc->add($this->end_key, 0);
    }

    public function push($elem) {
        $this->_lock();
        $i = $this->memc->increment($this->end_key);
        $this->memc->set(sprintf($this->item_key_format, $i), $elem);
        $this->_unlock();
        return $elem;
    }

    public function pop() {
        $this->_lock();
		$i = $this->memc->increment($this->start_key);
		$elem = $this->memc->get(sprintf($this->item_key_format, $i));
		if (false === $elem) { // eoq
			$this->memc->increment($this->end_key);
		}
        $this->_unlock();
		return $elem;
    }

    public function peek() {
        $i = $this->memc->get($this->start_key);
        return $this->memc->get(sprintf($this->item_key_format, $i+1));
    }

    public function count() {
        $this->_lock();
        $count = ($this->memc->get($this->end_key) - $this->memc->get($this->start_key));
        $this->_unlock();
		return $count;
    }

    public function is_empty() {
        return ($this->count() == 0);
    }

	// delete the entire queue
	public function delete() {
		$this->_lock();
		$start = $this->memc->get($this->start_key);
		$end = $this->memc->get($this->end_key);
		for ($i=$start; $i < $end; $i++) {
			$this->memc->delete(sprintf($this->item_key_format, $i+1));
		}
		$this->memc->set($this->start_key, $end);
		$this->_unlock();
	}

    private function _connect() {
        $this->memc = new memcache;
        $this->memc->addServer('localhost', '11211');
    }

    private function _lock() {
        // standard spin lock - expire in 2 seconds
        while ($this->memc->add($this->lock_key, '1', 0, 2)) {
            usleep(100);
        }
    }

    private function _unlock() {
        $this->memc->delete($this->lock_key);
    }

  }

// unit test
$q = new memq('testq');
assert($q->count() === 0);
assert($q->is_empty() === true);
$q->push('a');
$q->push('b');
$q->push('c');
assert($q->count() === 3);
assert($q->is_empty() === false);
assert($q->peek() === 'a');
assert($q->pop() === 'a');
assert($q->count() === 2);
assert($q->pop() === 'b');
$q->push('d');
$q2 = new memq('testq2');
$q2->push('a2');
assert($q->pop() === 'c');
assert($q->peek() === 'd');
assert($q->pop() === 'd');
assert($q->pop() === false);
assert($q->peek() === false);
assert($q2->pop() === 'a2');

// unit test for delete
$q3 = new memq('testq3');
$q3->push('a');
$q3->push('b');
$q3->push('c');
$q3->delete();
assert($q3->count() == 0);
