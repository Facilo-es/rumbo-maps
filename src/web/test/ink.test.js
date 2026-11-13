import { describe, it, expect } from 'vitest';
import { inkFor } from '../src/util.js';

describe('inkFor', () => {
  it('keeps white text on dark colours', () => {
    expect(inkFor('#17694f')).toBe('#fff');
    expect(inkFor('#1d4ed8')).toBe('#fff');
  });
  it('switches to dark text on light colours', () => {
    expect(inkFor('#f5c518')).toBe('#14201b');
    expect(inkFor('#ffffff')).toBe('#14201b');
  });
  it('ignores anything that is not a 6-digit hex colour', () => {
    expect(inkFor('red')).toBeNull();
    expect(inkFor('#fff')).toBeNull();
  });
});
